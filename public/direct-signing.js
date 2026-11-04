import * as pdfjs from '/vendor/pdfjs/pdf.mjs';
pdfjs.GlobalWorkerOptions.workerSrc = '/vendor/pdfjs/pdf.worker.mjs';
const el = id => document.getElementById(id);
const pad = el('signature-pad'), ctx = pad.getContext('2d'), sheet = el('pdf-sheet'), placed = el('placed-signature');
let pdf, ready = false, ink = false, drawing = false, renderId = 0;
const hotspots = JSON.parse(el('signature-form').dataset.hotspots);
let signatureRevision = 0;
const signatureStyles = {
    classic: 'italic 72px "Segoe Script", cursive',
    formal: 'italic 72px Georgia, serif',
    modern: '72px "Ink Free", "Comic Sans MS", cursive',
    allura: '88px "Signature Allura"',
    'great-vibes': '80px "Signature Great Vibes"',
    'dancing-script': '76px "Signature Dancing Script"',
    sacramento: '88px "Signature Sacramento"',
    caveat: '80px "Signature Caveat"',
};
const method = () => el('signature-method').value;
function place() {
    const page = Number(el('pdf-page').value);
    const fields = hotspots.fields;
    const display = (element, box) => {
        element.hidden = box.page !== page;
        element.style.left = `${box.x / hotspots.page_width * 100}%`;
        element.style.top = `${box.y / hotspots.page_height * 100}%`;
        element.style.width = `${box.width / hotspots.page_width * 100}%`;
        element.style.height = `${box.height / hotspots.page_height * 100}%`;
    };
    for (const [id, box] of Object.entries(fields)) display(el(`hotspot-${id}`), box);
    const name = el('signer-name').value.trim();
    for (const [id, value, element] of [
        ['full_name', name, el('printed-name')],
        ['signed_date', el('printed-date').dataset.date, el('printed-date')]
    ]) {
        const box = fields[id];
        display(element, {...box, x: box.x + 4, y: box.y + 2, width: box.width - 8});
        element.textContent = value;
        element.style.fontSize = `${Math.min(11, (box.width - 8) / Math.max(1, value.length)) / hotspots.page_width * sheet.clientWidth}px`;
    }
    const box = fields.signature;
    const width = Math.min(box.width, box.height * 4), height = width / 4;
    display(placed, {...box, x: box.x + (box.width - width) / 2, y: box.y + (box.height - height) / 2, width, height});
    placed.hidden = !ink || box.page !== page;
}
function sync() {
    placed.src = pad.toDataURL('image/png'); placed.hidden = !ink;
    el('save-signature').disabled = !ready || !ink;
    place();
}
function clear() { signatureRevision++; ctx.clearRect(0, 0, 800, 200); ink = false; sync(); }
async function typed() {
    if (method() !== 'type') return;
    clear();
    const name = el('signer-name').value.trim();
    const revision = signatureRevision;
    const font = signatureStyles[el('signature-style').value];
    el('sign-error').textContent = '';
    try {
        await document.fonts.load(font, name || 'Signature');
    } catch {
        if (revision === signatureRevision) el('sign-error').textContent = 'This signature style could not load. Please choose another style or retry.';
        return;
    }
    // A slow font must not overwrite a newer choice, drawing, upload or clear action.
    if (revision !== signatureRevision || method() !== 'type') return;
    ctx.font = font;
    ctx.fillStyle = '#222d25'; ctx.textAlign = 'left'; ctx.textBaseline = 'alphabetic';
    const metrics = ctx.measureText(name);
    const width = metrics.actualBoundingBoxLeft + metrics.actualBoundingBoxRight;
    const height = metrics.actualBoundingBoxAscent + metrics.actualBoundingBoxDescent;
    const scale = Math.min(1, 740 / Math.max(1, width), 160 / Math.max(1, height));
    ctx.save(); ctx.translate(400, 100); ctx.scale(scale, scale);
    ctx.fillText(name, (metrics.actualBoundingBoxLeft - metrics.actualBoundingBoxRight) / 2,
        (metrics.actualBoundingBoxAscent - metrics.actualBoundingBoxDescent) / 2);
    ctx.restore(); ink = name.length >= 2; sync();
}
el('signer-name').addEventListener('input', () => { typed(); place(); }); el('signature-style').addEventListener('change', typed);
new ResizeObserver(place).observe(sheet);
el('signature-method').addEventListener('change', () => {
    clear(); el('signature-upload').value = '';
    el('type-options').hidden = method() !== 'type'; el('upload-options').hidden = method() !== 'upload';
    el('signature-hint').textContent = method() === 'draw' ? 'Draw here using your mouse, finger, or pen.' : method() === 'upload' ? 'Your uploaded signature will appear here.' : 'Your typed name becomes the signature shown below.';
    typed();
});
el('clear-signature').addEventListener('click', () => { clear(); if (method() === 'type') el('signer-name').value = ''; el('signature-upload').value = ''; });
function point(event) { const r = pad.getBoundingClientRect(); return [(event.clientX-r.left)*800/r.width, (event.clientY-r.top)*200/r.height]; }
pad.addEventListener('pointerdown', event => {
    if (method() !== 'draw') return;
    event.preventDefault(); drawing = true; pad.setPointerCapture(event.pointerId);
    const [x,y] = point(event); ctx.beginPath(); ctx.moveTo(x,y); ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#222d25'; ctx.lineWidth = 3;
});
pad.addEventListener('pointermove', event => { if (!drawing) return; ctx.lineTo(...point(event)); ctx.stroke(); ink = true; sync(); });
for (const type of ['pointerup','pointercancel','lostpointercapture']) pad.addEventListener(type, () => { drawing = false; });
el('signature-upload').addEventListener('change', async event => {
    clear(); el('sign-error').textContent = ''; const file = event.target.files[0]; if (!file) return;
    if (!['image/png','image/jpeg'].includes(file.type) || file.size > 2*1024*1024) { el('sign-error').textContent = 'Choose a PNG or JPG image up to 2 MB.'; return; }
    try {
        const bitmap = await createImageBitmap(file);
        if (bitmap.width > 8000 || bitmap.height > 8000) { bitmap.close(); throw new Error(); }
        const scale = Math.min(760/bitmap.width, 170/bitmap.height);
        ctx.drawImage(bitmap, (800-bitmap.width*scale)/2, (200-bitmap.height*scale)/2, bitmap.width*scale, bitmap.height*scale);
        bitmap.close(); ink = true; sync();
    } catch { el('sign-error').textContent = 'This image could not be read. Try a smaller PNG or JPG.'; }
});
async function renderPage() {
    const id = ++renderId; ready = false; sync();
    try {
        const page = await pdf.getPage(Number(el('pdf-page').value));
        const viewport = page.getViewport({ scale: 1.5 });
        const buffer = document.createElement('canvas'); buffer.width = viewport.width; buffer.height = viewport.height;
        await page.render({ canvasContext: buffer.getContext('2d'), viewport }).promise;
        if (id !== renderId) return;
        const canvas = el('pdf-canvas'); canvas.width = buffer.width; canvas.height = buffer.height; canvas.getContext('2d').drawImage(buffer,0,0);
        ready = true; el('pdf-status').textContent = `Page ${el('pdf-page').value} of ${pdf.numPages}`; sync();
    } catch { el('pdf-status').textContent = 'PDF preview could not load. Reload to try again.'; }
}
el('pdf-page').addEventListener('change', renderPage);
el('jump-signing').addEventListener('click', () => { el('pdf-page').value = String(hotspots.fields.signature.page); renderPage(); });
el('signature-form').addEventListener('submit', event => {
    if (!ready || !ink) { event.preventDefault(); el('sign-error').textContent = 'Create a signature and wait for the PDF preview.'; return; }
    el('signature-data').value = pad.toDataURL('image/png'); el('save-signature').disabled = true; el('save-signature').textContent = 'Saving agreement…';
});
typed();
try {
    pdf = await pdfjs.getDocument({ url: '/direct-signing-demo/sample', isEvalSupported: false, standardFontDataUrl: '/vendor/pdfjs/standard_fonts/' }).promise;
    el('pdf-page').replaceChildren(...Array.from({length:pdf.numPages}, (_,i) => new Option(String(i+1), String(i+1))));
    el('pdf-page').value = String(hotspots.fields.signature.page); await renderPage();
} catch { el('pdf-status').textContent = 'PDF preview could not load. Reload to try again.'; }


