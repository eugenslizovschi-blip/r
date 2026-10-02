"""Build the decision-maker contact workbook from crawl + research notes."""
import csv
import json
import re

from openpyxl import Workbook
from openpyxl.styles import Alignment, Font, PatternFill
from openpyxl.utils import get_column_letter

crawl = json.load(open("crawl_results.json"))
people = {r[0]: r for r in csv.reader(open("people.tsv"), delimiter="\t")
          if r and r[0] != "domain"}
order = [l.split("|")[0].strip() for l in open("domains.txt") if l.strip()]

# Off-domain addresses that clearly belong to the same company / parent brand.
RELATED = ("athena.com", "sagehome.com", "covalent.com", "teachmeseries.com",
           "ssgh.cz", "bemoved.com.au", "secrettours.com", "gibbonswhistler.com",
           "evolutionlending.co.uk", "progressivemoney.co.uk", "starkey.com",
           "theexcellencecollection.com", "granasa.com.ec", "hanger.com",
           "hays-travel.co.uk", "iade.pt", "swissklip.com", "bolcorp.com.au",
           "comoshambhala.com", "universidadeeuropeia.pt", "queenstown-wanaka.nz",
           "omdia.com", "techtarget.com", "nakie.com.au", "universidadeuropea.es",
           "attentivemobile.com_x", "jaguarlandrover.com", "gnresound.com",
           "icicoach.com", "scalingco.com", "printwithme.com", "syos.com",
           "funraise.io", "frameworksecurity.com", "telusdigital.com",
           "poneygroup.com", "brunobanani.de", "justkampers.co.uk",
           "radiuspaymentsolutions.com", "rnib.org", "madametarot.com",
           "evolutionmoneygroup.co.uk", "reviews.co.uk", "dronline.com",
           "coachwithfitr.com", "medicinemarketplace.com", "feefo.com")
EXTRA_OK = {"wunlawealth@gmail.com", "thefivedenver@gmail.com"}
JUNK_LOCAL = {"user", "email", "my", "example", "test"}

ROLE = re.compile(
    r"^(info|contact|contato|kontakt|hello|hi|hey|sales|support|suporte|help|"
    r"service|customer|cs|team|admin|office|enquir|inquir|general|mail|"
    r"privacy|legal|security|dpo|gdpr|data|datenschutz|dataprotection|"
    r"personvern|direitos|compliance|press|media|pr|marketing|careers|jobs|"
    r"hr|talent|people|recruit|finance|account|billing|orders|webshop|"
    r"partners|events|groups|reservations|reception|members|corporate|"
    r"venues|sponsorship|commercial|vendors|introducers|new\.business|"
    r"atendimento|servicioalcliente|pre-vendas|helpline|employment|"
    r"fundraising|investors|innovation|contracts|unsubscribe|incident|"
    r"enterprise|rma|solutions|trade|coaching|foodie|guestexperience|"
    r"custserv|clientservices|membership|auditandrisk|complaint|"
    r"relationship|smartaccounts|spain|munich|london|eu|uk|destinations|"
    r"pro|consumerhelp|data_privacy|customersuccess|citations|"
    r"dpmanager|pressoffice|admission|kwaliteit|nazorg|clienten|"
    r"diretoria|bewerbung|fashion|jkworld|data-controller|beschwerde|"
    r"travelletter|medicines|scrm|rim|oxide|legaltech|carahsoft|"
    r"telushealth|datatrust|ti_gic|cypf|eyecare|lwws|tfl|digitalteam|"
    r"employmentline|employmentni|fpnandmarketing|eventos|director|"
    r"peopleteam|concierge|salesops|admiss|postgrado|creditor|sageadmin|gnresound|webshop|e-customerservices|"
    r"customerservices|comoshambhala|res\.|met|thehalkin|csestate)", re.I)
PRIVACY = re.compile(r"privacy|legal|security|dpo|gdpr|data|datenschutz|"
                     r"personvern|direitos|compliance|dpmanager|incident", re.I)
BUSINESS = re.compile(r"^(info|contact|contato|kontakt|hello|hi|hey|sales|"
                      r"team|admin|office|enquir[a-z]*|inquir[a-z]*|general|mail|"
                      r"partners|corporate|commercial|new\.business|pre-vendas|"
                      r"diretoria|solutions|investors|press|media|pr|"
                      r"pressoffice|marketing)(?![a-z.])", re.I)


def root(dom):
    parts = dom.lower().removeprefix("www.").split(".")
    if len(parts) > 2 and parts[-2] in ("co", "com", "org", "net", "ac"):
        parts = parts[:-1]
    return parts[-2] if len(parts) >= 2 else parts[0]


def clean_emails(dom, emails):
    r = root(dom)
    out = {}
    for e, url in emails.items():
        e = re.sub(r"^05%7c0\d%7c", "", e)
        local, _, edom = e.partition("@")
        if local in JUNK_LOCAL or ".when" in edom or "wpengine" in edom \
                or "cloudways" in edom or edom.endswith("comuk"):
            continue
        if local.startswith("n") and local[1:] in ("info",):
            continue  # "\ninfo" artefact
        ok = (r in edom) or any(edom.endswith(x) for x in RELATED) \
            or e in EXTRA_OK
        if ok:
            out[e] = url
    return out


LOCATION_DOMAINS = ("maidpro.com", "fyeo.nl", "excellenceresorts.com")


def classify(e):
    local, _, edom = e.partition("@")
    if edom in LOCATION_DOMAINS and not PRIVACY.search(local) \
            and not BUSINESS.search(local):
        return "Support / department / location"
    if edom == "comohotels.com" and not re.match(
            r"^(?!como|met|res)[a-z]{3,}\.[a-z]{3,}$", local) \
            and not PRIVACY.search(local) and not BUSINESS.search(local):
        return "Support / department / location"
    if PRIVACY.search(local):
        return "Privacy / legal / security"
    if BUSINESS.search(local):
        return "General / sales / press"
    if ROLE.search(local):
        return "Support / department / location"
    return "Named person"


def first_names(dm):
    names = re.findall(r"([A-ZÀ-Ž][a-zà-ž]+)(?:\s+[A-ZÀ-Ž\"]|\s*\()", dm)
    return {n.lower() for n in names}


wb = Workbook()
FONT = Font(name="Arial", size=10)
BOLD = Font(name="Arial", size=10, bold=True, color="FFFFFF")
HEAD_FILL = PatternFill("solid", fgColor="1F3864")
HL = PatternFill("solid", fgColor="E2EFDA")
WRAP = Alignment(wrap_text=True, vertical="top")

ws = wb.active
ws.title = "Decision Makers"
headers = ["Domain", "Decision makers (name & title)",
           "Email matching a decision maker", "Named-person emails on site",
           "General / sales / press emails", "Privacy / legal emails",
           "Email you supplied", "Source for decision makers", "Notes"]
ws.append(headers)

all_rows = []
maxlist = 8
for dom in order:
    raw = crawl[dom]
    cleaned = clean_emails(dom, raw["emails"])
    groups = {}
    for e, url in sorted(cleaned.items()):
        cat = classify(e)
        groups.setdefault(cat, []).append(e)
        all_rows.append([dom, e, cat, url])
    p = people.get(dom, [dom, "", ""])
    dm, src = p[1], p[2] if len(p) > 2 else ""
    fn = first_names(dm)
    named = groups.get("Named person", [])
    surnames = {w.lower() for w in re.findall(
        r"[A-ZÀ-Ž][a-zà-ž]+\s+([A-ZÀ-Ž][a-zà-ž']{3,})", dm)}
    match = [e for e in named
             if any(re.match(rf"^{re.escape(n)}([._]|$)", e.split("@")[0])
                    for n in fn)
             or any(sn in e.split("@")[0] for sn in surnames)]
    notes = []
    if not raw.get("reachable"):
        notes.append("Website blocked/unreachable for automated crawl")
    elif not cleaned:
        notes.append("No email published on homepage/contact/legal pages")
    if any(w in dm.lower() for w in ("verify", "differ", "uncertain",
                                      "unverified")):
        notes.append("Check decision-maker info (see column B)")
    given = crawl[dom].get("given", "")
    if given and "@" in given and given.lower() not in cleaned:
        notes.append("Your email not seen on crawled pages")

    def fmt(lst):
        if len(lst) > maxlist:
            return ", ".join(lst[:maxlist]) + f" (+{len(lst) - maxlist} more, see All Emails)"
        return ", ".join(lst)
    ws.append([dom, dm, ", ".join(match), fmt(named),
               fmt(groups.get("General / sales / press", [])),
               fmt(groups.get("Privacy / legal / security", [])),
               given, src, "; ".join(notes)])
    if match:
        ws.cell(ws.max_row, 3).fill = HL

widths = [26, 55, 30, 40, 40, 34, 30, 45, 34]
for i, w in enumerate(widths, 1):
    ws.column_dimensions[get_column_letter(i)].width = w
for row in ws.iter_rows():
    for c in row:
        c.font = FONT
        c.alignment = WRAP
for c in ws[1]:
    c.font = BOLD
    c.fill = HEAD_FILL
ws.freeze_panes = "B2"
ws.auto_filter.ref = ws.dimensions

ws2 = wb.create_sheet("All Emails Found")
ws2.append(["Domain", "Email", "Type", "Page where found"])
for r in all_rows:
    ws2.append(r)
for i, w in enumerate([26, 42, 30, 70], 1):
    ws2.column_dimensions[get_column_letter(i)].width = w
for row in ws2.iter_rows():
    for c in row:
        c.font = FONT
for c in ws2[1]:
    c.font = BOLD
    c.fill = HEAD_FILL
ws2.freeze_panes = "A2"
ws2.auto_filter.ref = ws2.dimensions

ws3 = wb.create_sheet("How This Was Built")
notes = [
    "How this list was built (2 Oct 2026)",
    "",
    "1. Emails: every domain's homepage plus contact / about / team / legal / "
    "privacy / imprint pages were crawled, and every email address published on "
    "them was recorded (sheet 'All Emails Found', with the page URL).",
    "2. Third-party noise was removed: regulators, PR agencies, website-platform "
    "addresses and open-source library author emails embedded in site code.",
    "3. Decision makers: owners / founders / CEOs / MDs were identified from public "
    "sources (company sites, Companies House and other registries, press, "
    "Crunchbase-type profiles). Source link given per row.",
    "4. Column C (green) = a published email whose name matches one of the "
    "decision makers.",
    "",
    "Not included on purpose: guessed or pattern-generated addresses "
    "(e.g. firstname@company.com). Only addresses the companies publish "
    "themselves are listed. To reach a named decision maker without a published "
    "address, use the general/sales email, LinkedIn, or a verification tool.",
    "",
    "Caveats: leadership changes often - rows marked 'verify' had conflicting or "
    "uncertain sources. ~25 sites blocked the crawler (bot protection); their "
    "email columns may be empty even though the site lists contacts. Before "
    "emailing, check local rules (GDPR / PECR in UK & EU, CAN-SPAM in US, CASL "
    "in Canada, LGPD in Brazil) - B2B outreach usually needs a relevant, "
    "legitimate-interest message and an easy opt-out.",
]
for n in notes:
    ws3.append([n])
ws3.column_dimensions["A"].width = 120
for row in ws3.iter_rows():
    for c in row:
        c.font = FONT
        c.alignment = Alignment(wrap_text=True, vertical="top")
ws3["A1"].font = Font(name="Arial", size=12, bold=True)

wb.save("decision_maker_emails.xlsx")
print("rows", ws.max_row - 1, "emails", len(all_rows),
      "matches", sum(1 for r in ws.iter_rows(min_row=2) if r[2].value))
