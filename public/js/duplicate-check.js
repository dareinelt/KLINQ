/* Live-Dublettenhinweis beim Anlegen von Herstellern */
(function () {
    "use strict";
    const input = document.querySelector("[data-duplicate-check]");
    const box = document.getElementById("duplicate-live");
    if (!input || !box) { return; }
    const url = input.getAttribute("data-duplicate-check");
    const original = input.value;

    const check = AppUI.debounce(async function () {
        const name = input.value.trim();
        if (name.length < 2 || name === original) { box.hidden = true; return; }
        try {
            const data = await AppUI.api(url + (url.indexOf("?") >= 0 ? "&" : "?") + "name=" + encodeURIComponent(name));
            if (!data.duplicates.length) { box.hidden = true; return; }
            box.innerHTML = '<div class="alert alert-warning alert-stacked alert-compact mb-0">' +
                '<span class="text-sm"><strong>Hinweis:</strong> Ähnliche Hersteller vorhanden:</span><ul class="duplicate-hint">' +
                data.duplicates.slice(0, 5).map(function (d) {
                    return '<li class="text-sm"><a href="/manufacturers/' + d.id + '">' + AppUI.escapeHtml(d.name) + '</a> – ' + AppUI.escapeHtml(d.reason) + (d.is_active ? "" : " · inaktiv") + "</li>";
                }).join("") + "</ul></div>";
            box.hidden = false;
        } catch (e) { box.hidden = true; }
    }, 300);
    input.addEventListener("input", check);
})();
