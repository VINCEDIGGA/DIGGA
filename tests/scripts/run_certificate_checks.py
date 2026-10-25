#!/usr/bin/env python3
"""Run nine local-certificate checks against the prototype validation API."""

import argparse
from datetime import datetime, timedelta, timezone
import hashlib
import json
from pathlib import Path
import sys
import urllib.error
import urllib.parse
import urllib.request


DEFAULT_CASES = Path(__file__).resolve().parents[1] / "data" / "certificate_cases.json"


def public_fixtures(now):
    from cryptography import x509
    from cryptography.x509.oid import NameOID
    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import rsa

    # The signing key stays in process memory and is never serialized, sent or saved.
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "Local certificate test fixture")])

    def certificate(start, end, serial):
        cert = (x509.CertificateBuilder().subject_name(name).issuer_name(name)
                .public_key(key.public_key()).serial_number(serial)
                .not_valid_before(start).not_valid_after(end).sign(key, hashes.SHA256()))
        return cert.public_bytes(serialization.Encoding.PEM).decode("ascii")

    current = certificate(now - timedelta(days=1), now + timedelta(days=30), 1001)
    return {
        "public_current": current,
        "public_expired": certificate(now - timedelta(days=31), now - timedelta(days=1), 1002),
        "public_future": certificate(now + timedelta(days=7), now + timedelta(days=37), 1003),
        "public_duplicate": current + current,
    }


def endpoint_url(base_url):
    parsed = urllib.parse.urlsplit(base_url)
    if parsed.scheme not in ("http", "https") or not parsed.netloc:
        raise ValueError("--base-url must be an HTTP(S) application root URL")
    if parsed.query or parsed.fragment or parsed.username or parsed.password:
        raise ValueError("--base-url must not contain credentials, query or fragment")
    return base_url.rstrip("/") + "/backend/api/sdk_validate.php"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", required=True, help="Root URL of a configured prototype")
    parser.add_argument("--output", type=Path, required=True, help="Destination JSON results file")
    parser.add_argument("--cases", type=Path, default=DEFAULT_CASES, help="Certificate case JSON file")
    parser.add_argument("--timeout", type=float, default=20, help="Per-request timeout in seconds")
    args = parser.parse_args()
    try:
        endpoint = endpoint_url(args.base_url)
        cases = json.loads(args.cases.read_text(encoding="utf-8-sig"))
        if args.timeout <= 0:
            raise ValueError("--timeout must be positive")
        now = datetime.now(timezone.utc)
        fixtures = public_fixtures(now)
    except ImportError:
        parser.error("Install the cryptography dependency declared in tests/requirements.txt")
    except (OSError, ValueError, TypeError) as error:
        parser.error(str(error))

    record = {
        "executed_at_utc": now.isoformat(), "endpoint": endpoint,
        "scope": "Local stateless certificate API; no language-model call",
        "fixture_note": "Synthetic public certificates; C2 replaces the historical supplied expired certificate",
        "private_key_fixture": "Signing key stays in memory; private-key/PFX inputs are dummy markers only",
        "case_count": len(cases), "results": [],
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    for case in cases:
        content = fixtures[case["generated_fixture"]] if "generated_fixture" in case else case["content"]
        expected = case["expected"]
        result = {"id": case["id"], "label": case["label"],
                  "input_sha256": hashlib.sha256(content.encode("utf-8")).hexdigest(),
                  "input_bytes": len(content.encode("utf-8")), "expected": expected}
        payload = json.dumps({"format": "certificate", "content": content}).encode("utf-8")
        request = urllib.request.Request(endpoint, data=payload,
                                         headers={"Content-Type": "application/json"}, method="POST")
        body = {}
        try:
            try:
                with urllib.request.urlopen(request, timeout=args.timeout) as response:
                    status, raw = response.status, response.read()
            except urllib.error.HTTPError as error:
                status, raw = error.code, error.read()
            result.update(http_status=status, response_bytes=len(raw),
                          response_sha256=hashlib.sha256(raw).hexdigest())
            try:
                parsed = json.loads(raw.decode("utf-8-sig"))
                result["valid_json"] = isinstance(parsed, dict)
                if isinstance(parsed, dict):
                    body = parsed
                    result["response"] = body
            except (UnicodeDecodeError, json.JSONDecodeError):
                result.update(valid_json=False, response_error="non_json_response")
            failed_rules = [check.get("id") for check in body.get("checks", [])
                            if check.get("status") == "fail"]
            matched = (result["valid_json"] and status == expected["http"]
                       and (expected["valid"] is None or body.get("valid") is expected["valid"])
                       and (expected["failed_rule"] is None or expected["failed_rule"] in failed_rules))
            if body.get("normalized"):
                normalized = body["normalized"]
                matched = matched and normalized.get("stored") is False and normalized.get("trust_verified") is False
            result.update(failed_rules=failed_rules, matches_expectation=bool(matched))
        except (urllib.error.URLError, TimeoutError, OSError):
            result.update(valid_json=False, matches_expectation=False, response_error="transport_failure")
        record["results"].append(result)
        record["passed_expectations"] = sum(item["matches_expectation"] for item in record["results"])
        args.output.write_text(json.dumps(record, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(case["id"], result.get("http_status", "no_response"),
              "MATCH" if result["matches_expectation"] else "MISMATCH", flush=True)
    return 0 if record["passed_expectations"] == len(cases) else 1


if __name__ == "__main__":
    sys.exit(main())
