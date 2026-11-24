/*
 * MATE – Vorschau für Office-Dateien (Word, Excel, PowerPoint)
 *
 * Die Datei wird direkt im Browser aufbereitet. Sie verlässt MATE nicht: kein fremder Dienst,
 * keine Programmteile von fremden Servern. Die Bibliotheken liegen im selben Ordner und werden
 * erst geladen, wenn jemand eine Vorschau öffnet (siehe LIZENZEN.txt).
 *
 *   MateVorschau.art("Steckbrief.docx")  → "word" | "excel" | "ppt" | null
 *   MateVorschau.zeigen(box, arrayBuffer, name)  → Promise
 */
(function () {
  "use strict";
  const V = "?v=1";
  const BASIS = (document.currentScript && document.currentScript.src.replace(/[?#].*$/, "").replace(/[^/]*$/, "")) || "vorschau/";
  const geladen = {};
  function skript(n) {
    return geladen[n] || (geladen[n] = new Promise((ok, nein) => {
      const s = document.createElement("script");
      s.src = BASIS + n + V;
      s.onload = ok;
      s.onerror = () => { delete geladen[n]; s.remove(); nein(new Error("Laden fehlgeschlagen: " + n)); };
      document.head.appendChild(s);
    }));
  }

  function art(name) {
    const e = (String(name || "").split(".").pop() || "").toLowerCase();
    if (/^(docx|docm|dotx)$/.test(e)) return "word";
    if (/^(xlsx|xlsm|xls|ods)$/.test(e)) return "excel";
    if (/^(pptx|pptm|ppsx)$/.test(e)) return "ppt";
    return null;
  }

  // ---------- Aussehen ----------
  const css = document.createElement("style");
  css.textContent = `
    .mv{position:relative;width:100%;max-height:70vh;overflow:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--line,#ddd);border-radius:8px;background:#eef0f3;color:#111}
    .mv-info{padding:24px 16px;text-align:center;color:var(--muted,#666);background:var(--card,#fff)}
    .mv-a-word .ms-docx-wrapper{padding:12px!important;background:#eef0f3!important;align-items:flex-start!important}
    .mv-a-word .ms-docx-wrapper>section.ms-docx{margin:0 auto 12px!important;box-shadow:0 1px 4px rgba(0,0,0,.18)!important}
    .mv-tabs{position:sticky;top:0;left:0;z-index:2;display:flex;gap:4px;flex-wrap:wrap;padding:6px;background:var(--card,#fff);border-bottom:1px solid var(--line,#ddd)}
    .mv-tabs button{font:inherit;font-size:.85rem;padding:4px 10px;border:1px solid var(--line,#ccc);border-radius:999px;background:#fff;color:#222;cursor:pointer}
    .mv-tabs button[aria-selected="true"]{background:#1d6f42;border-color:#1d6f42;color:#fff}
    .mv-blatt{background:#fff;display:inline-block;min-width:100%}
    .mv-blatt table{border-collapse:collapse;font-size:.85rem;font-family:Calibri,Arial,sans-serif}
    .mv-blatt td,.mv-blatt th{border:1px solid #d4d4d4;padding:3px 6px;white-space:nowrap;vertical-align:bottom;min-width:2.5em}
    .mv-blatt th{background:#f3f3f3;color:#555;font-weight:400;text-align:center;position:sticky;top:0}
    .mv-blatt th.mv-z{position:sticky;left:0;z-index:1}
    .mv-blatt td.mv-n{text-align:right}
    .mv-hinweis{padding:6px 10px;font-size:.8rem;color:#555;background:#fff8e1}
    .mv-ppt{padding:8px;background:#eef0f3;overflow:hidden}
    .mv-ppt .pptx-preview-wrapper{background:transparent!important;height:auto!important;overflow:visible!important;margin:0 auto}
    .mv-ppt .pptx-preview-slide-wrapper{margin:0 auto 10px!important;box-shadow:0 1px 4px rgba(0,0,0,.18)}
  `;
  document.head.appendChild(css);

  // ---------- Sicherheitsnetz ----------
  // Abgaben kommen von Kindern: aus der Datei darf nichts Ausführbares in die Seite gelangen
  // (z. B. ein Link mit „javascript:“). Alles, was die Bibliotheken einfügen, wird sofort geprüft.
  const WEG = "script,iframe,frame,object,embed,link,meta,base,form,input,button,textarea,select,foreignObject,animate,set";
  const SICHER = /^(https?:|mailto:|#|blob:|data:image\/(png|jpe?g|gif|webp|bmp|svg\+xml);)/i;
  function saeubern(wurzel) {
    if (!wurzel || wurzel.nodeType !== 1) return;
    const liste = [wurzel, ...wurzel.querySelectorAll("*")];
    for (const el of liste) {
      if (el !== wurzel && el.matches && el.matches(WEG) && !el.closest(".mv-tabs")) { el.remove(); continue; }
      for (const at of [...el.attributes]) {
        const n = at.name.toLowerCase();
        if (n.startsWith("on")) { el.removeAttribute(at.name); continue; }
        if (n === "href" || n === "src" || n === "xlink:href" || n === "action" || n === "formaction" || n === "srcset") {
          const v = at.value.trim();
          if (v && !SICHER.test(v)) el.removeAttribute(at.name);
        }
      }
      if (el.tagName === "A") { el.setAttribute("target", "_blank"); el.setAttribute("rel", "noopener noreferrer"); }
    }
  }
  function waechter(box) {
    const mo = new MutationObserver((ms) => {
      for (const m of ms) {
        if (m.type === "attributes") saeubern(m.target);
        else m.addedNodes.forEach((n) => saeubern(n));
      }
    });
    mo.observe(box, { childList: true, subtree: true, attributes: true, attributeFilter: ["href", "src", "xlink:href", "srcset", "action", "onclick", "onerror", "onload", "onmouseover", "onfocus"] });
    return mo;
  }

  // ---------- Word ----------
  async function word(box, daten) {
    await skript("jszip.min.js");
    await skript("docx-preview.min.js");
    const ziel = document.createElement("div");
    box.appendChild(ziel);
    await window.docx.renderAsync(daten, ziel, ziel, {
      className: "ms-docx", inWrapper: true, breakPages: true, ignoreLastRenderedPageBreak: true,
      useBase64URL: true,          // Bilder und Schriften als data:, passt zur Sicherheitsrichtlinie
      renderAltChunks: false,      // eingebettetes HTML nie anzeigen
      renderComments: false, renderChanges: false, experimental: false,
    });
    // Seite auf die Fensterbreite verkleinern (am Handy wichtig)
    const hulle = ziel.querySelector(".ms-docx-wrapper");
    if (hulle) {
      const breite = Math.max(...[...hulle.children].map((s) => s.offsetWidth || 0), 1) + 24;
      const f = Math.min(1, (box.clientWidth - 2) / breite);
      if (f < 1) hulle.style.zoom = f.toFixed(3);
    }
  }

  // ---------- Excel ----------
  const MAX_Z = 500, MAX_S = 40;
  function spalte(i) { let s = ""; i++; while (i > 0) { const m = (i - 1) % 26; s = String.fromCharCode(65 + m) + s; i = Math.floor((i - 1) / 26); } return s; }
  function esc(t) { return String(t ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c])); }
  function blattHtml(X, ws) {
    if (!ws || !ws["!ref"]) return { html: '<div class="mv-info">Dieses Blatt ist leer.</div>', gekuerzt: false };
    const r = X.utils.decode_range(ws["!ref"]);
    const z1 = Math.min(r.e.r, r.s.r + MAX_Z - 1), s1 = Math.min(r.e.c, r.s.c + MAX_S - 1);
    const gekuerzt = z1 < r.e.r || s1 < r.e.c;
    const verb = new Map(), weg = new Set();
    for (const m of ws["!merges"] || []) {
      verb.set(m.s.r + ":" + m.s.c, m);
      for (let a = m.s.r; a <= m.e.r; a++) for (let b = m.s.c; b <= m.e.c; b++) if (a !== m.s.r || b !== m.s.c) weg.add(a + ":" + b);
    }
    let h = '<table><thead><tr><th class="mv-z"></th>';
    for (let c = r.s.c; c <= s1; c++) h += `<th>${spalte(c)}</th>`;
    h += "</tr></thead><tbody>";
    for (let z = r.s.r; z <= z1; z++) {
      h += `<tr><th class="mv-z">${z + 1}</th>`;
      for (let c = r.s.c; c <= s1; c++) {
        const k = z + ":" + c;
        if (weg.has(k)) continue;
        const zelle = ws[X.utils.encode_cell({ r: z, c })];
        const m = verb.get(k);
        const span = m ? ` rowspan="${Math.min(m.e.r, z1) - z + 1}" colspan="${Math.min(m.e.c, s1) - c + 1}"` : "";
        let text = "";
        if (zelle) text = zelle.w != null ? zelle.w : (zelle.v != null ? String(zelle.v) : (zelle.f ? "=" + zelle.f : ""));
        const zahl = zelle && (zelle.t === "n" || zelle.t === "d");
        h += `<td${span}${zahl ? ' class="mv-n"' : ""}>${esc(text)}</td>`;
      }
      h += "</tr>";
    }
    return { html: h + "</tbody></table>", gekuerzt };
  }
  async function excel(box, daten) {
    await skript("xlsx.core.min.js");
    const X = window.XLSX;
    const wb = X.read(new Uint8Array(daten), { type: "array", sheetRows: MAX_Z + 1, cellHTML: false, cellStyles: false, bookVBA: false, WTF: false });
    const namen = wb.SheetNames.filter((n) => !(wb.Workbook && wb.Workbook.Sheets && (wb.Workbook.Sheets.find((s) => s.name === n) || {}).Hidden));
    const tabs = document.createElement("div");
    tabs.className = "mv-tabs";
    const blatt = document.createElement("div");
    blatt.className = "mv-blatt";
    const zeige = (i) => {
      const { html, gekuerzt } = blattHtml(X, wb.Sheets[namen[i]]);
      blatt.innerHTML = (gekuerzt ? `<div class="mv-hinweis">Vorschau zeigt höchstens ${MAX_Z} Zeilen und ${MAX_S} Spalten. Für alles: Datei herunterladen.</div>` : "") + html;
      tabs.querySelectorAll("button").forEach((b, j) => b.setAttribute("aria-selected", String(i === j)));
      box.scrollTop = 0; box.scrollLeft = 0;
    };
    namen.forEach((n, i) => {
      const b = document.createElement("button");
      b.type = "button"; b.textContent = n; b.setAttribute("role", "tab");
      b.addEventListener("click", (e) => { e.preventDefault(); e.stopPropagation(); zeige(i); });
      tabs.appendChild(b);
    });
    if (namen.length > 1) box.appendChild(tabs);
    box.appendChild(blatt);
    if (!namen.length) { blatt.innerHTML = '<div class="mv-info">Diese Datei enthält keine Tabelle.</div>'; return; }
    zeige(0);
  }

  // ---------- PowerPoint ----------
  async function folienFormat(daten) {
    try {
      await skript("jszip.min.js");
      const zip = await window.JSZip.loadAsync(daten);
      const xml = await zip.file("ppt/presentation.xml").async("string");
      const m = /<p:sldSz[^>]*\bcx="(\d+)"[^>]*\bcy="(\d+)"/.exec(xml) || /<p:sldSz[^>]*\bcy="(\d+)"[^>]*\bcx="(\d+)"/.exec(xml);
      if (m) { const cx = +m[1], cy = +m[2]; if (cx > 0 && cy > 0) return /cy=.*cx=/.test(m[0]) ? cy / cx : cx / cy; }
    } catch (e) { /* Standard nehmen */ }
    return 16 / 9;
  }
  async function ppt(box, daten) {
    const verh = await folienFormat(daten);
    await skript("pptx-preview.umd.js");
    const ziel = document.createElement("div");
    ziel.className = "mv-ppt";
    box.appendChild(ziel);
    const breite = Math.max(200, Math.floor(box.clientWidth - 18));
    const p = window.pptxPreview.init(ziel, { width: breite, height: Math.round(breite / verh), mode: "list" });
    await p.preview(daten);
  }

  async function zeigen(box, daten, name) {
    const a = art(name);
    if (!a) throw new Error("Format ohne Vorschau");
    box.classList.add("mv", "mv-a-" + a);
    box.innerHTML = '<div class="mv-info">Vorschau wird vorbereitet …</div>';
    const info = box.firstChild;
    const mo = waechter(box);
    try {
      if (a === "word") await word(box, daten);
      else if (a === "excel") await excel(box, daten);
      else await ppt(box, daten);
      info.remove();
      saeubern(box);
    } catch (e) {
      [...box.childNodes].forEach((n) => { if (n !== info) n.remove(); });
      info.textContent = "Für diese Datei klappt die Vorschau leider nicht. Lade sie herunter, um sie zu öffnen.";
      throw e;
    } finally {
      // Wächter bleibt aktiv (PowerPoint zeichnet z. B. Diagramme nach), bis das Fenster zugeht
      const weg = new MutationObserver(() => { if (!box.isConnected) { mo.disconnect(); weg.disconnect(); } });
      weg.observe(document.body, { childList: true, subtree: true });
    }
  }

  window.MateVorschau = { art, zeigen };
})();
