(function () {
  const multipleSigners = document.querySelector(
    '[data-application-field="multiple_signers"]'
  );
  const extraSignerFields = Array.prototype.slice.call(
    document.querySelectorAll(
      [
        '[data-application-field="signer2_name"]',
        '[data-application-field="signer2_email"]',
        '[data-application-field="signer3_name"]',
        '[data-application-field="signer3_email"]',
      ].join(", ")
    )
  );
  const emailFields = Array.prototype.slice.call(
    document.querySelectorAll('#context-form input[type="email"]')
  );
  const organizationNumber = document.querySelector("#organization-number");
  const invoiceOrganizationNumber = document.querySelector(
    '[data-application-field="invoice_org_number"]'
  );

  function dispatchFieldEvents(field) {
    field.dispatchEvent(new Event("input", { bubbles: true }));
    field.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function normalizeOrgNumber(value) {
    return String(value || "").replace(/\D/g, "");
  }

  function validateOrgNumber(field) {
    if (!field) {
      return true;
    }
    const digits = normalizeOrgNumber(field.value);
    const valid = digits === "" || /^\d{10}$/.test(digits);
    field.setCustomValidity(
      valid ? "" : "Organisationsnumret måste innehålla 10 siffror."
    );
    return valid;
  }

  function validateEmail(field) {
    if (!field || field.value.trim() === "") {
      if (field) {
        field.setCustomValidity("");
      }
      return true;
    }
    field.setCustomValidity("");
    if (field.checkValidity()) {
      return true;
    }
    field.setCustomValidity("Skriv en giltig e-postadress.");
    return false;
  }

  function setExtraSignerFieldsVisible() {
    if (!multipleSigners) {
      return;
    }
    const shouldShow = multipleSigners.value === "ja";
    extraSignerFields.forEach(function (field) {
      const label = field.closest("label");
      if (label) {
        label.hidden = !shouldShow;
      }
      field.disabled = !shouldShow;
      if (!shouldShow && field.value !== "") {
        field.value = "";
        dispatchFieldEvents(field);
      }
    });
  }

  function copyOrganizationNumber() {
    if (!organizationNumber || !invoiceOrganizationNumber) {
      return;
    }

    if (!validateOrgNumber(organizationNumber)) {
      organizationNumber.reportValidity();
      return;
    }

    const value = organizationNumber.value.trim();
    if (value === "") {
      organizationNumber.setCustomValidity(
        "Fyll i organisationsnummer i avsnitt 2 först."
      );
      organizationNumber.reportValidity();
      organizationNumber.setCustomValidity("");
      return;
    }

    const existing = invoiceOrganizationNumber.value.trim();
    if (
      existing !== "" &&
      existing !== value &&
      !window.confirm("Det finns redan ett organisationsnummer. Vill du ersätta det?")
    ) {
      return;
    }

    invoiceOrganizationNumber.value = value;
    invoiceOrganizationNumber.setCustomValidity("");
    dispatchFieldEvents(invoiceOrganizationNumber);
  }

  if (multipleSigners) {
    multipleSigners.addEventListener("change", setExtraSignerFieldsVisible);
    document.addEventListener("digga:context-loaded", setExtraSignerFieldsVisible);
    setExtraSignerFieldsVisible();
  }

  emailFields.forEach(function (field) {
    field.addEventListener("blur", function () {
      if (!validateEmail(field)) {
        field.reportValidity();
      }
    });
  });

  [organizationNumber, invoiceOrganizationNumber].forEach(function (field) {
    if (!field) {
      return;
    }
    field.addEventListener("blur", function () {
      if (!validateOrgNumber(field)) {
        field.reportValidity();
      }
    });
  });

  if (organizationNumber && invoiceOrganizationNumber) {
    const copyButton = document.createElement("button");
    copyButton.type = "button";
    copyButton.className = "btn-secondary underlag-inline-button";
    copyButton.textContent = "Kopiera organisationsnummer";
    invoiceOrganizationNumber.closest("label").appendChild(copyButton);
    copyButton.addEventListener("click", copyOrganizationNumber);
  }
})();
