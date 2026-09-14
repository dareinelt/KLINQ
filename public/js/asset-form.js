/* Asset-Formular: typabhängige Felder, Artikel-Übernahme, Live-Dublettenprüfung, übergeordnetes Asset */
(function () {
    "use strict";
    const form = document.querySelector("[data-asset-form]");
    if (!form) { return; }

    const typeField = form.querySelector("[name=asset_type_id]");
    const articlePicker = form.querySelector("[data-article-picker]");
    const articleValue = articlePicker ? articlePicker.querySelector("[data-picker-value]") : null;
    const manufacturerDisplay = form.querySelector("[data-manufacturer-display]");
    const manufacturerId = form.querySelector("[data-manufacturer-id]");
    const categorySelect = form.querySelector("[name=asset_category_id]");
    const checkUrl = form.getAttribute("data-check-url");
    const assetId = form.getAttribute("data-asset-id");
    const baseSearchUrl = articlePicker ? articlePicker.getAttribute("data-search-url") : "";

    function selectedTypeOption() {
        if (typeField.tagName === "SELECT") { return typeField.options[typeField.selectedIndex] || null; }
        return typeField; // hidden input beim Bearbeiten trägt die data-Attribute selbst
    }

    /* Nur relevante Kennungsfelder anzeigen; gefüllte Felder bleiben sichtbar */
    function applyTypeFields() {
        const opt = selectedTypeOption();
        const flags = {
            serial: !opt || !typeField.value || opt.getAttribute("data-serial") !== "0",
            mac: !!opt && typeField.value && opt.getAttribute("data-mac") === "1",
            imei: !!opt && typeField.value && opt.getAttribute("data-imei") === "1"
        };
        Object.keys(flags).forEach(function (key) {
            const group = form.querySelector('[data-field="' + key + '"]');
            if (!group) { return; }
            const input = group.querySelector("input");
            group.hidden = !flags[key] && !(input && input.value.trim() !== "");
        });
        // Artikel-Suche auf den gewählten Typ einschränken; passt der gewählte Artikel nicht mehr, Auswahl aufheben
        if (articlePicker) {
            const type = typeField.value;
            articlePicker.setAttribute("data-search-url", baseSearchUrl + (type ? "?asset_type_id=" + encodeURIComponent(type) : ""));
            const articleType = articleValue.getAttribute("data-type");
            if (type && articleValue.value && articleType && articleType !== type) {
                const clear = articlePicker.querySelector("[data-picker-clear]");
                if (clear) { clear.click(); }
            }
        }
    }

    /* Artikel gewählt → Typ, Hersteller, Kategorie übernehmen */
    function applyArticle(item) {
        if (!item) {
            if (manufacturerDisplay) { manufacturerDisplay.value = ""; }
            if (manufacturerId) { manufacturerId.value = ""; }
            articleValue.removeAttribute("data-type");
            return;
        }
        articleValue.setAttribute("data-type", String(item.asset_type_id));
        if (typeField.tagName === "SELECT" && typeField.value !== String(item.asset_type_id)) {
            typeField.value = String(item.asset_type_id);
            typeField.dispatchEvent(new Event("change"));
        }
        if (manufacturerDisplay) { manufacturerDisplay.value = item.manufacturer_name || ""; }
        if (manufacturerId) { manufacturerId.value = item.manufacturer_id || ""; }
        if (categorySelect && item.asset_category_id) {
            categorySelect.value = String(item.asset_category_id);
        }
        const nameInput = form.querySelector("[name=name]");
        if (nameInput && !nameInput.value) { nameInput.placeholder = item.name; }
    }

    /* Live-Dublettenprüfung für Seriennummer / MAC / IMEI */
    function bindCheck(input) {
        const field = input.getAttribute("data-check");
        const list = form.querySelector('[data-check-hints="' + field + '"]');
        if (!list || !checkUrl) { return; }
        const run = AppUI.debounce(async function () {
            const value = input.value.trim();
            if (value.length < 3) { list.hidden = true; list.innerHTML = ""; return; }
            const params = new URLSearchParams({ field: field, value: value, asset_type_id: typeField.value || "" });
            if (assetId) { params.set("exclude", assetId); }
            try {
                const data = await AppUI.api(checkUrl + "?" + params.toString());
                list.innerHTML = data.hits.map(function (h) {
                    return '<li class="' + (h.level === "error" ? "is-error" : "is-warning") + '">' +
                        AppUI.escapeHtml(h.message) + ': <a href="/assets/' + h.asset.id + '" target="_blank" rel="noopener">' +
                        AppUI.escapeHtml(h.asset.inventory_number) + "</a></li>";
                }).join("");
                list.hidden = data.hits.length === 0;
            } catch (e) { list.hidden = true; }
        }, 350);
        input.addEventListener("input", run);
    }

    /* Übergeordnetes Asset über Inventarnummer suchen */
    function bindParent() {
        const text = form.querySelector("[data-parent-input]");
        const hidden = form.querySelector("[data-parent-id]");
        const datalist = document.getElementById("parent-assets");
        if (!text || !hidden || !datalist) { return; }
        const known = {};
        if (text.value.trim() !== "" && hidden.value !== "") { known[text.value.trim().toUpperCase()] = hidden.value; }
        const search = AppUI.debounce(async function () {
            const q = text.value.trim();
            if (q.length < 2) { return; }
            try {
                const data = await AppUI.api("/api/assets/search?q=" + encodeURIComponent(q));
                datalist.innerHTML = "";
                data.items.forEach(function (item) {
                    if (assetId && String(item.id) === assetId) { return; }
                    known[item.inventory_number] = item.id;
                    const opt = document.createElement("option");
                    opt.value = item.inventory_number;
                    opt.label = item.name ? item.inventory_number + " – " + item.name : item.inventory_number;
                    datalist.appendChild(opt);
                });
                resolve();
            } catch (e) { /* Vorschläge sind optional */ }
        }, 250);
        function resolve() {
            const key = text.value.trim().toUpperCase();
            hidden.value = known[key] || "";
        }
        text.addEventListener("input", function () { resolve(); search(); });
        text.addEventListener("change", resolve);
        form.addEventListener("submit", function () {
            if (text.value.trim() === "") { hidden.value = ""; }
        });
    }

    typeField.addEventListener("change", applyTypeFields);
    if (articlePicker) { articlePicker.addEventListener("picker:change", function (e) { applyArticle(e.detail); }); }
    Array.prototype.forEach.call(form.querySelectorAll("[data-check]"), bindCheck);
    bindParent();
    applyTypeFields();
})();
