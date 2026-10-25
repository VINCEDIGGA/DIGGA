#!/usr/bin/env python3
"""Submit the saved questions to the prototype's normal chat API."""

import argparse
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


DEFAULT_QUESTIONS = Path(__file__).resolve().parents[1] / "questions" / "ai_questions.json"


def endpoint_url(base_url):
    parsed = urllib.parse.urlsplit(base_url)
    if parsed.scheme not in ("http", "https") or not parsed.netloc:
        raise ValueError("--base-url must be an HTTP(S) application root URL")
    if parsed.query or parsed.fragment or parsed.username or parsed.password:
        raise ValueError("--base-url must not contain credentials, query or fragment")
    return base_url.rstrip("/") + "/backend/api/chat.php"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", required=True, help="Root URL of a configured prototype")
    parser.add_argument("--output", type=Path, required=True, help="Destination JSON results file")
    parser.add_argument("--question", action="append", help="Question ID; repeat to select several")
    parser.add_argument("--questions", type=Path, default=DEFAULT_QUESTIONS, help="Question JSON file")
    parser.add_argument("--timeout", type=float, default=180, help="Per-request timeout in seconds")
    args = parser.parse_args()
    try:
        endpoint = endpoint_url(args.base_url)
        questions = json.loads(args.questions.read_text(encoding="utf-8-sig"))
        ids = [item["id"] for item in questions]
        if len(ids) != len(set(ids)):
            raise ValueError("Question IDs must be unique")
        if args.timeout <= 0:
            raise ValueError("--timeout must be positive")
        unknown = set(args.question or []) - set(ids)
        if unknown:
            raise ValueError("Unknown question IDs: " + ", ".join(sorted(unknown)))
        if args.question:
            questions = [item for item in questions if item["id"] in args.question]
    except (OSError, ValueError, KeyError, TypeError) as error:
        parser.error(str(error))

    record = {
        "endpoint": endpoint,
        "started_utc": datetime.now(timezone.utc).isoformat(),
        "method": {
            "payload_fields": ["question"],
            "shared_comparison_prompt_sent": False,
            "knowledge_files_sent": False,
            "case_context_sent": False,
            "conversation_history_sent": False,
            "automatic_quality_score": False,
        },
        "results": [],
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    failed = False
    for item in questions:
        payload = json.dumps({"question": item["question"]}, ensure_ascii=False).encode("utf-8")
        request = urllib.request.Request(endpoint, data=payload,
                                         headers={"Content-Type": "application/json"}, method="POST")
        started = time.monotonic()
        result = {"id": item["id"], "question": item["question"]}
        try:
            try:
                with urllib.request.urlopen(request, timeout=args.timeout) as response:
                    status, raw = response.status, response.read()
            except urllib.error.HTTPError as error:
                status, raw = error.code, error.read()
            result.update(http_status=status, response_bytes=len(raw),
                          response_sha256=hashlib.sha256(raw).hexdigest())
            try:
                body = json.loads(raw.decode("utf-8-sig"))
                result["valid_json"] = isinstance(body, dict)
                if isinstance(body, dict):
                    # Only the user-visible answer, source evidence and verification are retained.
                    result["response"] = {key: body[key] for key in (
                        "mode", "answer", "claims", "sources", "verification",
                        "missing_information", "error", "message") if key in body}
                else:
                    result["response_error"] = "expected_json_object"
            except (UnicodeDecodeError, json.JSONDecodeError):
                result.update(valid_json=False, response_error="non_json_response")
            result["request_succeeded"] = 200 <= status < 300 and result["valid_json"]
        except (urllib.error.URLError, TimeoutError, OSError):
            # Do not publish raw server diagnostics, local runtime paths or credentials.
            result.update(request_succeeded=False, response_error="transport_failure")
        result["seconds"] = round(time.monotonic() - started, 2)
        record["results"].append(result)
        failed = failed or not result["request_succeeded"]
        record["completed_questions"] = len(record["results"])
        args.output.write_text(json.dumps(record, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(item["id"], result.get("http_status", "no_response"),
              "received" if result["request_succeeded"] else "request_failed", flush=True)
    record["completed_utc"] = datetime.now(timezone.utc).isoformat()
    args.output.write_text(json.dumps(record, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
