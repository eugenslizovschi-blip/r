"""Collect publicly published email addresses from each domain's own website.

Visits the homepage, follows on-site links that look like contact / about /
team / legal / privacy / imprint pages, plus a few common fallback paths,
and records every email address that appears in the page source.
"""
import concurrent.futures as cf
import html
import json
import re
import sys
from urllib.parse import urljoin, urlparse

import requests
import urllib3

urllib3.disable_warnings()

UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/126.0 Safari/537.36")
EMAIL_RE = re.compile(r"[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,24}")
HREF_RE = re.compile(r'href\s*=\s*["\']([^"\'#]+)["\']', re.I)
CF_RE = re.compile(r'data-cfemail\s*=\s*["\']([0-9a-fA-F]+)["\']')
KEYWORDS = re.compile(
    r"contact|kontakt|contato|contacto|contatti|about|over-ons|overons|"
    r"sobre|uber-uns|ueber-uns|team|leadership|management|founder|"
    r"impressum|imprint|mentions|legal|privacy|privacidade|privacidad|"
    r"datenschutz|press|media|investor|careers|company|who-we-are|"
    r"om-oss|o-nas|kapcsolat|support|help", re.I)
FALLBACKS = ["/contact", "/contact-us", "/about", "/about-us", "/privacy",
             "/privacy-policy", "/impressum", "/legal", "/team",
             "/pages/contact", "/pages/about", "/pages/contact-us"]
BAD_TLDS = {"png", "jpg", "jpeg", "gif", "svg", "webp", "css", "js", "ico",
            "avif", "woff", "woff2", "ttf", "mp4", "pdf"}
BAD_DOMAINS = ("sentry.io", "wixpress.com", "example.com", "domain.com",
               "email.com", "yourdomain", "sentry-next", "ingest.sentry",
               "godaddy.com", "w3.org", "schema.org", "mysite.com",
               "yoursite.com", "company.com", "test.com", "2x.png")


def decode_cf(hexstr):
    try:
        key = int(hexstr[:2], 16)
        return "".join(chr(int(hexstr[i:i + 2], 16) ^ key)
                       for i in range(2, len(hexstr), 2))
    except Exception:
        return ""


def clean(email):
    e = email.strip(".-_").lower()
    e = re.sub(r"^(u003e|u0022|x22|20|3e)", "", e)
    local, _, dom = e.partition("@")
    if not local or not dom or "." not in dom:
        return None
    if dom.rsplit(".", 1)[-1] in BAD_TLDS:
        return None
    if any(b in dom for b in BAD_DOMAINS):
        return None
    if re.fullmatch(r"[0-9a-f]{20,}", local):  # hashes (sentry keys etc.)
        return None
    if len(local) > 40:
        return None
    return e


def fetch(session, url):
    try:
        r = session.get(url, timeout=15, allow_redirects=True, verify=True)
        ctype = r.headers.get("content-type", "")
        if r.status_code >= 400 or ("html" not in ctype and "text" not in ctype):
            return None, r.url
        return r.text[:3_000_000], r.url
    except Exception:
        return None, url


def extract(text):
    text_u = html.unescape(text).replace("%40", "@").replace("&#64;", "@")
    found = set()
    for m in EMAIL_RE.findall(text_u):
        c = clean(m)
        if c:
            found.add(c)
    for h in CF_RE.findall(text):
        c = clean(decode_cf(h))
        if c:
            found.add(c)
    return found


def crawl(domain):
    s = requests.Session()
    s.headers.update({"User-Agent": UA, "Accept-Language": "en-US,en;q=0.9"})
    result = {"domain": domain, "emails": {}, "pages": 0, "reachable": False}

    home, final = fetch(s, f"https://{domain}/")
    if home is None:
        home, final = fetch(s, f"https://www.{domain}/")
    if home is None:
        home, final = fetch(s, f"http://{domain}/")
    if home is None:
        return result
    result["reachable"] = True
    base = final
    host = urlparse(base).netloc.lower().removeprefix("www.")
    root = domain.removeprefix("www.")

    def add(found, url):
        for e in found:
            result["emails"].setdefault(e, url)

    add(extract(home), base)
    result["pages"] = 1

    links = []
    for href in HREF_RE.findall(home):
        u = urljoin(base, html.unescape(href))
        p = urlparse(u)
        if p.scheme not in ("http", "https"):
            continue
        h = p.netloc.lower().removeprefix("www.")
        if not (h == host or h.endswith(root) or root in h):
            continue
        if KEYWORDS.search(p.path) and u not in links:
            links.append(u.split("?")[0])
    for fb in FALLBACKS:
        u = urljoin(base, fb)
        if u not in links:
            links.append(u)
    seen = {base}
    for u in links[:22]:
        if u in seen:
            continue
        seen.add(u)
        page, furl = fetch(s, u)
        if page:
            result["pages"] += 1
            add(extract(page), furl)
    return result


def main():
    rows = []
    for line in open("domains.txt"):
        line = line.strip()
        if not line:
            continue
        dom, _, given = line.partition("|")
        rows.append((dom.strip(), given.strip()))
    out = {}
    with cf.ThreadPoolExecutor(max_workers=24) as ex:
        futs = {ex.submit(crawl, d): (d, g) for d, g in rows}
        for i, f in enumerate(cf.as_completed(futs), 1):
            d, g = futs[f]
            try:
                r = f.result()
            except Exception as exc:  # noqa: BLE001
                r = {"domain": d, "emails": {}, "error": str(exc)}
            r["given"] = g
            out[d] = r
            print(f"[{i}/{len(rows)}] {d}: {len(r['emails'])} emails",
                  file=sys.stderr, flush=True)
    ordered = {d: out[d] for d, _ in rows}
    json.dump(ordered, open("crawl_results.json", "w"), indent=1)


if __name__ == "__main__":
    main()
