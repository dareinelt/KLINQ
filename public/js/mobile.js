/* Mobile Erfassung: Komfortfunktionen für Entnahme-/Rückgabeformulare.
   Absenden per klassischem POST (funktioniert ohne JS); Offline-Warteschlange folgt in Phase 10. */
(function () {
    "use strict";
    const form = document.getElementById("movement-form");
    if (!form) { return; }

    // Rückgabe: Zielstatus-Hinweis je Zustand
    const targetSelect = form.querySelector("[data-target-status]");
    const targetHint = form.querySelector("[data-target-hint]");
    const damageBox = document.getElementById("f-has-damage");
    const defaults = { ok: "Lagerbestand", worn: "Lagerbestand", damaged: "Reparatur", defective: "Defekt" };
    function updateTargetHint() {
        if (!targetSelect || !targetHint) { return; }
        const cond = form.querySelector("[data-condition]:checked");
        const code = cond ? cond.value : "ok";
        const auto = damageBox && damageBox.checked && code !== "defective" ? "Reparatur" : (defaults[code] || "Lagerbestand");
        targetHint.textContent = targetSelect.value === "" ? "Automatisch: " + auto : "";
        if (cond && (code === "damaged" || code === "defective") && damageBox && !damageBox.checked && !damageBox.dataset.touched) {
            damageBox.checked = true;
            damageBox.dispatchEvent(new Event("change", { bubbles: true }));
        }
    }
    form.querySelectorAll("[data-condition]").forEach(function (r) { r.addEventListener("change", updateTargetHint); });
    if (targetSelect) { targetSelect.addEventListener("change", updateTargetHint); }
    if (damageBox) { damageBox.addEventListener("click", function () { damageBox.dataset.touched = "1"; updateTargetHint(); }); }
    updateTargetHint();

    // Fotovorschau
    const photoInput = form.querySelector("[data-photo-input]");
    const previews = form.querySelector("[data-photo-previews]");
    if (photoInput && previews) {
        photoInput.addEventListener("change", function () {
            previews.innerHTML = "";
            Array.from(photoInput.files || []).slice(0, 8).forEach(function (file) {
                if (!file.type.startsWith("image/")) { return; }
                const img = document.createElement("img");
                img.alt = file.name;
                img.src = URL.createObjectURL(file);
                img.addEventListener("load", function () { URL.revokeObjectURL(img.src); });
                previews.appendChild(img);
            });
        });
    }

    // Offline: Formular nicht abschicken, Hinweis anzeigen (Warteschlange in Phase 10)
    form.addEventListener("submit", function (e) {
        if (!navigator.onLine) {
            e.preventDefault();
            if (window.AppUI && window.AppUI.toast) {
                window.AppUI.toast("Offline – bitte erneut senden, sobald eine Verbindung besteht.", "warning");
            } else {
                window.alert("Offline – bitte erneut senden, sobald eine Verbindung besteht.");
            }
        }
    });
})();
