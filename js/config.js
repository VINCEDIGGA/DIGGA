(function () {
  // Resolve from this script so root and /poc/ pages reach the same backend.
  const base = new URL("../backend/api/", document.currentScript.src);
  const endpoint = function (name) { return new URL(name, base).href; };
  window.DIGGA_CONFIG = {
    ragChatEndpoint: endpoint("chat.php"),
    stepContextEndpoint: endpoint("step_context.php"),
    caseAttachmentsEndpoint: endpoint("case_attachments.php"),
    reformatEndpoint: endpoint("reformat_files.php"),
    sdkResourcesEndpoint: endpoint("sdk_resources.php"),
    sdkValidationEndpoint: endpoint("sdk_validate.php"),
    sdkAddressbookEndpoint: endpoint("sdk_addressbook.php"),
  };
})();
