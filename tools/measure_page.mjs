import { chromium } from 'playwright-core';
const file = process.argv[2];
const b = await chromium.launch({ executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args:['--no-sandbox'] });
const out = {};
const p = await b.newPage({ viewport:{width:1440,height:1000} });
await p.goto('file://'+file, { waitUntil:'load', timeout:60000 });
await p.waitForTimeout(2500);

out.typography = await p.evaluate(() => {
  const px = el => parseFloat(getComputedStyle(el).fontSize);
  const lh = el => getComputedStyle(el).lineHeight;
  const vis = el => el.offsetParent !== null || el.getClientRects().length > 0;
  const grab = sel => [...document.querySelectorAll(sel)].filter(vis);
  const h1 = grab('h1'), h2 = grab('h2'), h3 = grab('h3');
  const body = document.body;
  const words = el => (el.innerText||'').trim().split(/\s+/).filter(Boolean).length;
  const lines = el => Math.round(el.getBoundingClientRect().height / parseFloat(getComputedStyle(el).lineHeight||px(el)*1.4));
  return {
    h1: h1.map(e=>({px:px(e), lh:lh(e), words:words(e), lines:lines(e), text:(e.innerText||'').trim().slice(0,80)})),
    h2_count: h2.length,
    h2_sizes: [...new Set(h2.map(e=>px(e)))].sort((a,b)=>b-a),
    h2_max_words: Math.max(0,...h2.map(words)),
    h2_over9: h2.filter(e=>words(e)>9).map(e=>({w:words(e), t:(e.innerText||'').trim().slice(0,70)})),
    h3_count: h3.length,
    h3_sizes: [...new Set(h3.map(e=>px(e)))].sort((a,b)=>b-a),
    body_px: px(body),
    body_lh: lh(body),
  };
});

out.text = await p.evaluate(() => {
  const t = document.body.innerText.replace(/‌/g,'‌');
  const words = t.trim().split(/\s+/).filter(Boolean);
  const B = '[^\\u0600-\\u06FFA-Za-z0-9]';
  const count = re => (t.match(new RegExp(re,'g'))||[]).length;
  return {
    total_words: words.length,
    first150: words.slice(0,150).join(' '),
    shoma: count(`(?:^|${B})شما(?:${B}|$)`),
    shoma_forms: count(`(?:^|${B})شما(?:ست|را|یی)?(?:${B}|$)`),
    ma: count(`(?:^|${B})ما(?:${B}|$)`),
    ma_forms: count(`(?:^|${B})ما(?:ست)?(?:${B}|$)`),
  };
});

out.structure = await p.evaluate(() => {
  const ids = [...document.querySelectorAll('[id]')].map(e=>e.id);
  const dup = ids.filter((v,i)=>ids.indexOf(v)!==i);
  const imgs = [...document.querySelectorAll('img')];
  return {
    sections: document.querySelectorAll('section').length,
    ids: ids.length, duplicate_ids: [...new Set(dup)],
    images: imgs.length,
    images_no_alt: imgs.filter(i=>!i.getAttribute('alt')).length,
    images_empty_alt: imgs.filter(i=>i.getAttribute('alt')==='').length,
    jsonld: [...document.querySelectorAll('script[type="application/ld+json"]')].map(s=>{
      try{ const j=JSON.parse(s.textContent); const g=j['@graph']||[j];
           return g.map(x=>x['@type']).flat(); }catch(e){ return 'PARSE_ERROR'; }
    }).flat(),
    meta_description: (document.querySelector('meta[name="description"]')||{}).content || null,
    title: document.title,
    lang: document.documentElement.lang, dir: document.documentElement.dir,
  };
});

for (const [w,h] of [[1440,1000],[1024,900],[390,844],[360,800]]) {
  await p.setViewportSize({width:w,height:h});
  await p.waitForTimeout(600);
  out['overflow_'+w] = await p.evaluate(()=>({
    scrollW: document.documentElement.scrollWidth,
    clientW: document.documentElement.clientWidth,
    overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
  }));
}
console.log(JSON.stringify(out,null,1));
await b.close();
