// Hämta rullistan för fler undertecknare
const flerUndertecknare = document.querySelector(
    '[data-application-field="multiple_signers"]'
);

// Hämta de fyra extra fälten
const extraFalt = document.querySelectorAll(
    '[data-application-field="signer2_name"], ' +
    '[data-application-field="signer2_email"], ' +
    '[data-application-field="signer3_name"], ' +
    '[data-application-field="signer3_email"]'
);

// Visar eller döljer extra undertecknare
function visaExtraFalt() {

    // Kontrollera om användaren har valt Ja
    const visa = flerUndertecknare.value === "ja";

    // Gå igenom de fyra extra fälten
    extraFalt.forEach(function(falt) {

        // Dölj eller visa hela fältet med dess rubrik
        falt.closest("label").style.display = visa ? "" : "none";

    });
}

// Kör funktionen när användaren ändrar sitt val
if (flerUndertecknare) {
    flerUndertecknare.addEventListener("change", visaExtraFalt);

    // Kontrollera även valet när sidan öppnas
    visaExtraFalt();
}

// Hämta alla e-postfält i formuläret
const epostFalt = document.querySelectorAll(
    '#context-form input[type="email"]'
);

// Kontrollera om e-postadressen är giltig
function kontrolleraEpost(falt) {
    const epost = falt.value.trim();

    // Tomma fält kontrolleras inte här
    if (epost === "") {
        return;
    }

    if (!falt.checkValidity()) {
        alert("Du måste skriva en giltig e-postadress!");
    }
}

// Koppla kontrollen till alla e-postfält
epostFalt.forEach(function(falt) {

    falt.addEventListener("blur", function() {
        kontrolleraEpost(falt);
    });

});


if (flerUndertecknare) {

    let senasteVal = flerUndertecknare.value;

    setInterval(function() {
        if (flerUndertecknare.value !== senasteVal) {
            senasteVal = flerUndertecknare.value;
            visaExtraFalt();
        }
    }, 500);

}


// Hämta organisationsnummer från avsnitt 2
const organisationsnummer = document.querySelector("#organization-number");

// Hämta organisationsnummer från faktureringen i avsnitt 3
const fakturaOrganisationsnummer = document.querySelector(
    '[data-application-field="invoice_org_number"]'
);


// Kontrollera att organisationsnumret har rätt format
function kontrolleraOrganisationsnummer(falt) {
    // Hämta texten som användaren har skrivit
    const nummer = falt.value.trim();

    // Kontrollera inte tomma fält
    if (nummer === "") {
        return;
    }

    // Ta bort bindestreck
    const siffror = nummer.replace("-", "");

    // Kontrollera att det är exakt 10 siffror
    if (!/^\d{10}$/.test(siffror)) {
        alert("Organisationsnumret måste innehålla 10 siffror!");
    }
}

// Kontrollera organisationsnummer när användaren lämnar fältet
if (organisationsnummer) {
    organisationsnummer.addEventListener("blur", function() {
        kontrolleraOrganisationsnummer(organisationsnummer);
    });
}

// Gör samma kontroll för fakturans organisationsnummer
if (fakturaOrganisationsnummer) {
    fakturaOrganisationsnummer.addEventListener("blur", function() {
        kontrolleraOrganisationsnummer(fakturaOrganisationsnummer);
    });
}


// Skapa en knapp för att kopiera organisationsnummer
const kopieraKnapp = document.createElement("button");

kopieraKnapp.type = "button";
kopieraKnapp.textContent = "Kopiera organisationsnummer";


// Placera knappen under fakturans organisationsnummer
if (fakturaOrganisationsnummer) {
    fakturaOrganisationsnummer.closest("label").appendChild(kopieraKnapp);
}


// Kopiera organisationsnummer när användaren klickar
kopieraKnapp.addEventListener("click", function() {

    // Kontrollera att båda fälten finns
    if (!organisationsnummer || !fakturaOrganisationsnummer) {
        return;
    }

    // Hämta organisationsnumret från avsnitt 2
    const nummer = organisationsnummer.value.trim();

    // Kontrollera om fältet är tomt
    if (nummer === "") {
        alert("Fyll i organisationsnummer i avsnitt 2 först!");
        return;
    }

    // Ta bort bindestreck
    const siffror = nummer.replace("-", "");

    // Kontrollera att numret innehåller exakt 10 siffror
    if (!/^\d{10}$/.test(siffror)) {
        alert("Organisationsnumret har fel format och kan inte kopieras!");
        return;
    }

    // Fråga innan ett annat nummer ersätts
    if (fakturaOrganisationsnummer.value.trim() !== "" &&
        fakturaOrganisationsnummer.value !== nummer) {

        const svar = confirm("Det finns redan ett organisationsnummer. Vill du ersätta det?");

        if (!svar) {
            return;
        }
    }

    // Kopiera numret till avsnitt 3
    fakturaOrganisationsnummer.value = nummer;
});

