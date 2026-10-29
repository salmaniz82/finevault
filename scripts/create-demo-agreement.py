"""Rebuild the fictional, two-page agreement used by the DocuSign sandbox demo."""
from pathlib import Path
from reportlab.pdfgen import canvas
from reportlab.lib.colors import HexColor, white
from reportlab.lib.utils import simpleSplit

target = Path(__file__).resolve().parents[1] / 'resources/demo/fineworth-onboarding.pdf'
target.parent.mkdir(parents=True, exist_ok=True)
c = canvas.Canvas(str(target), pagesize=(595, 842))
c.setTitle('Fineworth - fictional acquisition and onboarding agreement')
c.setAuthor('Fineworth prototype')
olive, copper = HexColor('#24352d'), HexColor('#a36e45')

def text(x, y, value, size=10, bold=False, color=olive):
    c.setFillColor(color)
    c.setFont('Helvetica-Bold' if bold else 'Helvetica', size)
    c.drawString(x, y, value)

def page(number, title):
    c.setFillColor(olive); c.rect(0, 738, 595, 104, fill=1, stroke=0)
    text(50, 797, 'FINEWORTH', 21, True, white)
    text(50, 771, title, 12, False, white)
    text(50, 716, 'DEMONSTRATION ONLY - FICTIONAL DATA - NO REAL TRANSACTION', 9, True, copper)
    c.setStrokeColor(HexColor('#dedbd3')); c.line(50, 55, 545, 55)
    text(50, 38, 'FW-DEMO-2026-001 | DocuSign developer sandbox | 29 October 2026', 8)
    text(505, 38, f'{number} / 2', 8)

def paragraph(y, heading, body, compact=False):
    text(50, y, heading, 11, True)
    size, leading = (9, 12) if compact else (10, 15)
    for n, line in enumerate(simpleSplit(body, 'Helvetica', size, 490)):
        text(50, y-18-n*leading, line, size)
    return y-30-len(simpleSplit(body, 'Helvetica', size, 490))*leading

page(1, 'Cask acquisition & onboarding agreement')
text(50, 678, 'Your cask acquisition', 18, True)
text(50, 657, 'Client full name', 10)
c.setStrokeColor(copper); c.line(155, 650, 540, 650)
text(50, 631, 'Sample counterparty: Fineworth Demonstration (fictional)', 10)
rows = [
 ('Trade / cask reference', 'FW-DEMO-2026-001 / GB-1842'),
 ('Distillery / whisky name', 'Glenbrae (imaginary) / Glenbrae 2018 Single Malt'),
 ('Cask type / nominal capacity', 'First-fill bourbon barrel / 200 L'),
 ('Fill date / sample regauge date', '15 June 2018 / 20 October 2026'),
 ('Original litres of alcohol (OLA)', '127.0 L of pure alcohol at filling'),
 ('Regauged litres of alcohol (RLA)', '113.4 L of pure alcohol at sample regauge'),
 ('Bulk liquid / recorded ABV', '180.0 L / 63.0% (180.0 x 63.0% = 113.4 RLA)'),
 ('Storage', 'Caledonian Demo Bond, Scotland (fictional)'),
 ('Purchase price', 'GBP 12,500.00'),
 ('Deposit paid externally', 'GBP 2,500.00 on 29 October 2026 (20%)'),
 ('Balance / due date', 'GBP 10,000.00 / 12 November 2026'),
 ('Annual management fee', '1.0% of purchase price = GBP 125.00 per year'),
 ('Brokerage fee on future sale', '2.0% of gross sale proceeds, only if sale arranged'),
]
y = 600
for i, (label, value) in enumerate(rows):
    if i % 2 == 0:
        c.setFillColor(HexColor('#f4f2ec')); c.rect(45, y-14, 505, 31, fill=1, stroke=0)
    text(52, y, label, 9, True); text(253, y, value, 8.5); y -= 31
paragraph(165, 'Onboarding activity', 'Review the cask particulars and the sample terms on page 2. In the portal, the client can view required documents and record their review. In this separate sandbox demonstration, the admin can act as the fictional client and sign through DocuSign.')
c.showPage()
page(2, 'Sample terms & client acknowledgement')
y = 679
for heading, body in [
 ('1. Acquisition and balance', 'The fictional deposit is recorded as received outside the portal. The sample balance is due by 12 November 2026. There is no portal payment facility. In this sample journey, the team confirms cleared funds and ownership paperwork before marking acquisition complete.'),
 ('2. Cask particulars and storage', 'The particulars describe one imaginary cask in bonded storage. OLA and RLA are distinct dated measurements. Maturation can reduce volume and change strength. The sample storage reference is illustrative and is not evidence of title, insurance or warehouse acceptance.'),
 ('3. Fees and exclusions', 'For illustration, annual management is 1.0% of the purchase price, billed annually from completion. Brokerage is 2.0% of future gross sale proceeds only when Fineworth arranges a sale. Storage, insurance, bottling, delivery and taxes are excluded from these sample figures and require a separate agreed schedule.'),
 ('4. Client review and next steps', 'The client reviews the agreement and supplies any documents requested by the team through the private portal. Compliance records remain staff-only. Acknowledging a document does not by itself approve compliance or confirm payment. Repeat trades would have their own paperwork and activity.'),
 ('5. Demonstration status', 'All identities, assets, figures, fee rules, dates and terms in this PDF are invented for software demonstration. This is not an approved Fineworth contract, investment recommendation or real purchase. The sandbox signature does not initiate a real transaction.'),
]:
    y = paragraph(y, heading, body, compact=True)
# DocuSign coordinates use top-left origin. Keep tab locations in sync with DocusignDemo.
text(50, 339, 'Client acknowledgement - sandbox demonstration', 12, True)
text(50, 319, 'I have reviewed this sample document and understand its demonstration status.', 10)
c.setStrokeColor(copper)
c.line(50, 240, 300, 240); c.line(345, 240, 540, 240)
text(50, 225, 'Client signature', 9); text(345, 225, 'Date signed', 9)
c.line(50, 185, 300, 185)
text(50, 169, 'Client full name', 9)
text(50, 119, 'Sample terms must be replaced with client-approved wording before real use.', 9, True, copper)
c.save()
print(target)

# General onboarding guide: safe for the existing fictional client dashboard,
# with no trade prices, legal signature or confidential compliance material.
guide = target.parent / 'fineworth-onboarding-guide.pdf'
c = canvas.Canvas(str(guide), pagesize=(595, 842))
c.setTitle('Fineworth - client onboarding guide (demonstration)')
c.setAuthor('Fineworth prototype')
page(1, 'Your private vault - onboarding guide')
text(50, 675, 'A few steps to get started.', 21, True)
y = 630
for heading, body in [
    ('1. Check your account details', 'Review your name and contact email in My account. Contact your portfolio team if anything needs correction. Keep your login credentials private.'),
    ('2. Review documents from your team', 'Open the onboarding documents on your dashboard and read them carefully. Record your review when ready. Your acknowledgement confirms that you have read that version; it is not an electronic signature.'),
    ('3. Supply requested documents', 'Use Documents to upload onboarding files requested by your team. Follow their instructions about which records are needed. Uploads remain private to staff and show a review status; review feedback explains any corrections needed.'),
    ('4. Wait for your team to confirm next steps', 'Your team will confirm any required checks and when you can proceed. A document acknowledgement alone does not confirm payment, compliance approval or ownership.'),
    ('5. Return whenever you need', 'Your approved records stay in the document vault. Use Service requests for available holding services and check your dashboard for updates.'),
]:
    y = paragraph(y, heading, body)
text(50, 110, 'Fictional demonstration guide - final onboarding requirements need confirmation.', 9, True, copper)
# Correct the generic two-page footer for this separate one-page guide.
c.setFillColor(white); c.rect(495, 27, 50, 22, fill=1, stroke=0)
text(505, 38, '1 / 1', 8)
c.save()
print(guide)
