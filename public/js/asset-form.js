/* Asset-Formular: typabhängige Felder, Artikel-Übernahme, Live-Dublettenprüfung, übergeordnetes Asset */
(function () {
    "use strict";
    const form = document.querySelector("[data-asset-form]");
    if (!form) { return; }

    const typeField = form.querySelector("[name=asset_type_id]");
    const articleSelect = form.querySelector("[data-article-select]");
    const manufacturerSelect = form.querySelector("[name=manufacturer_id]");
    const categorySelect = form.querySelector("[name=asset_category_id]");
    const checkUrl = form.getAttribute("data-check-url");
    const assetId = form.getAttribute("data-asset-id");

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
        // Artikel-Liste auf Typ einschränken
        if (articleSelect) {
            const type = typeField.value;
            Array.prototype.forEach.call(articleSelect.options, function (o) {
                if (!o.value) { return; }
                const visible = !type || o.getAttribute("data-type") === type;
                o.hidden = !visible;
                o.disabled = !visible;
                if (o.selected && !visible) { articleSelect.value = ""; }
            });
        }
    }

    /* Artikel gewählt → Typ, Hersteller, Kategorie übernehmen (nur leere Felder) */
    function applyArticle() {
        const o = articleSelect.options[articleSelect.selectedIndex];
        if (!o || !o.value) { return; }
        if (typeField.tagName === "SELECT" && !typeField.value) {
            typeField.value = o.getAttribute("data-type");
            typeField.dispatchEvent(new Event("change"));
        }
        if (manufacturerSelect && !manufacturerSelect.value) {
            manufacturerSelect.value = o.getAttribute("data-manufacturer");
        }
        const cat = o.getAttribute("data-category");
        if (categorySelect && !categorySelect.value && cat && cat !== "0") {
            categorySelect.value = cat;
        }
        const nameInput = form.querySelector("[name=name]");
        if (nameInput && !nameInput.value) { nameInput.placeholder = o.getAttribute("data-name"); }
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
    if (articleSelect) { articleSelect.addEventListener("change", applyArticle); }
    Array.prototype.forEach.call(form.querySelectorAll("[data-check]"), bindCheck);
    bindParent();
    applyTypeFields();
})();
