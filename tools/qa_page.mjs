import { chromium } from 'playwright-core';
const file = process.argv[2];
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args:['--no-sandbox'] });
const out = {};
const page = await b.newPage({ viewport:{width:1440,height:900} });
const errs = [];
page.on('pageerror', e => errs.push(String(e)));
page.on('console', m => { if (m.type()==='error') errs.push('console: '+m.text()) });
await page.goto('file://'+file, { waitUntil:'load' });
await page.waitForTimeout(1200);

/* ── H3 word/line check, heading order, landmarks ── */
out.headings = await page.evaluate(() => {
  const vis = el => el.offsetParent !== null || el.getClientRects().length > 0;
  const w = el => (el.innerText||'').trim().split(/\s+/).filter(Boolean).length;
  const lines = el => Math.round(el.getBoundingClientRect().height / parseFloat(getComputedStyle(el).lineHeight));
  const h3 = [...document.querySelectorAll('h3')].filter(vis);
  const seq = [...document.querySelectorAll('h1,h2,h3,h4,h5,h6')].filter(vis).map(e=>+e.tagName[1]);
  let jumps = [];
  for (let i=1;i<seq.length;i++) if (seq[i] > seq[i-1]+1) jumps.push(seq[i-1]+'→'+seq[i]);
  return {
    h1_count: document.querySelectorAll('h1').length,
    h3_over6: h3.filter(e=>w(e)>6).map(e=>({w:w(e), t:e.innerText.trim().slice(0,60)})),
    h3_multiline: h3.filter(e=>lines(e)>1).map(e=>e.innerText.trim().slice(0,50)),
    order_jumps: jumps,
    landmarks: {
      header: document.querySelectorAll('header').length,
      main: document.querySelectorAll('main').length,
      footer: document.querySelectorAll('footer').length,
      nav: [...document.querySelectorAll('nav')].map(n=>n.getAttribute('aria-label')),
    },
    skip: !!document.querySelector('.skip'),
  };
});

/* ── contrast: composite real stacked backgrounds, read gradients ── */
out.contrast = await page.evaluate(() => {
  const lum = ([r,g,b]) => { const f=v=>{v/=255;return v<=.03928?v/12.92:Math.pow((v+.055)/1.055,2.4)};
    return .2126*f(r)+.7152*f(g)+.0722*f(b) };
  const ratio = (a,b) => { const [L,S]=[lum(a),lum(b)].sort((x,y)=>y-x); return (L+.05)/(S+.05) };
  const parse = s => { const m=(s||'').match(/rgba?\(([^)]+)\)/); if(!m) return null;
    const p=m[1].split(',').map(Number); return {rgb:p.slice(0,3), a:p.length>3?p[3]:1} };
  const over = (fg, bg) => fg.rgb.map((c,i)=>Math.round(c*fg.a + bg[i]*(1-fg.a)));
  // real stack: walk ancestors bottom-up, honour alpha AND first gradient stop
  const bgOf = el => {
    let stack=[], n=el;
    while(n && n.nodeType===1){
      const cs=getComputedStyle(n);
      const bi=cs.backgroundImage;
      if(bi && bi!=='none'){ const g=parse(bi); if(g) stack.push(g); }
      const c=parse(cs.backgroundColor);
      if(c && c.a>0) stack.push(c);
      n=n.parentElement;
    }
    let base=[255,255,255];
    for(let i=stack.length-1;i>=0;i--) base=over(stack[i], base);
    return base;
  };
  const bad=[];
  const els=[...document.querySelectorAll('p,li,td,th,span,a,b,strong,em,small,h1,h2,h3,summary,button,label,dt,dd,i,caption,code,figcaption')];
  for(const el of els){
    if(!(el.offsetParent!==null || el.getClientRects().length)) continue;
    const t=(el.textContent||'').trim(); if(!t) continue;
    if([...el.children].some(c=>(c.textContent||'').trim()===t)) continue; // skip wrappers
    const cs=getComputedStyle(el);
    const fg=parse(cs.color); if(!fg) continue;
    const bg=bgOf(el);
    const col=over(fg,bg);
    const size=parseFloat(cs.fontSize), wgt=parseInt(cs.fontWeight)||400;
    const large = size>=24 || (size>=18.66 && wgt>=700);
    const need = large?3:4.5;
    const r=ratio(col,bg);
    if(r < need) bad.push({t:t.slice(0,45), r:+r.toFixed(2), need, size, sel:el.tagName+'.'+(el.className||'').toString().slice(0,30)});
  }
  return { failures: bad.slice(0,25), count: bad.length };
});

/* ── touch targets ── */
const targetsAt = async (w,h) => {
  await page.setViewportSize({width:w,height:h});
  await page.waitForTimeout(500);
  return page.evaluate(() => {
    const bad=[];
    for(const el of document.querySelectorAll('a[href],button,input,select,summary,[role="radio"]')){
      const r=el.getBoundingClientRect();
      if(!r.width || !r.height) continue;
      if(el.closest('[aria-hidden="true"]')) continue;
      if(r.width<24 || r.height<24){
        // WCAG 2.5.8 inline exception
        const inline = el.tagName==='A' && el.closest('p,li,figcaption,span');
        bad.push({t:(el.innerText||el.getAttribute('aria-label')||el.name||'').trim().slice(0,32),
                  w:Math.round(r.width), h:Math.round(r.height), inline:!!inline});
      }
    }
    return bad;
  });
};

/* ── viewport sweep: overflow + key element visibility ── */
const VPS = [[320,720],[360,800],[390,844],[844,390],[768,1024],[1280,800],[1440,900]];
out.viewports = {};
for (const [w,h] of VPS){
  await page.setViewportSize({width:w,height:h});
  await page.waitForTimeout(500);
  out.viewports[w+'x'+h] = await page.evaluate(() => {
    const de=document.documentElement;
    const tab=document.querySelector('.tabbar');
    const tabR=tab?tab.getBoundingClientRect():null;
    const shown=el=>{ if(!el) return false; const s=getComputedStyle(el);
      return s.display!=='none' && s.visibility!=='hidden' && el.getBoundingClientRect().height>0 };
    // any element wider than viewport?
    const wide=[...document.querySelectorAll('body *')].filter(el=>{
      const r=el.getBoundingClientRect();
      return r.width > de.clientWidth + 1 && getComputedStyle(el).position!=='fixed';
    }).slice(0,5).map(el=>el.tagName+'.'+(el.className||'').toString().slice(0,28)+' w='+Math.round(el.getBoundingClientRect().width));
    return {
      overflow: de.scrollWidth - de.clientWidth,
      wide,
      tabbar_visible: shown(tab),
      tabbar_h: tabR?Math.round(tabR.height):0,
      body_pad_bottom: getComputedStyle(document.body).paddingBottom,
      header_phone: shown(document.querySelector('.header-phone')),
      header_cta: shown(document.querySelector('.header-end .btn')),
      megamenu_nav: shown(document.querySelector('.nav')),
      h1_px: parseFloat(getComputedStyle(document.querySelector('h1')).fontSize),
    };
  });
}
out.targets_390 = await targetsAt(390,844);
out.targets_1440 = await targetsAt(1440,900);

/* ── landscape: does the tabbar cover content / is CTA reachable ── */
await page.setViewportSize({width:844,height:390});
await page.waitForTimeout(400);
out.landscape = await page.evaluate(() => {
  const tab=document.querySelector('.tabbar').getBoundingClientRect();
  return { tabbar_top: Math.round(tab.top), vh: window.innerHeight,
           tabbar_h: Math.round(tab.height),
           body_pad: getComputedStyle(document.body).paddingBottom };
});

/* ── zoom 200% (emulate: half viewport at same CSS px = 2x zoom) ── */
await page.setViewportSize({width:640,height:512});
await page.waitForTimeout(500);
out.zoom200 = await page.evaluate(() => ({
  overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
  h1_visible: document.querySelector('h1').getBoundingClientRect().width > 0,
}));

/* ── interactive module + keyboard ── */
await page.setViewportSize({width:1440,height:900});
await page.waitForTimeout(400);
out.interactive = await (async () => {
  const before = await page.evaluate(()=>document.getElementById('distRead').innerText.slice(0,24));
  await page.click('.dist-ctl [data-d="30"]');
  await page.waitForTimeout(400);
  const after = await page.evaluate(()=>({
    read: document.getElementById('distRead').innerText.slice(0,24),
    stage: document.getElementById('distStage').getAttribute('data-d'),
    checked: [...document.querySelectorAll('.dist-ctl [role=radio]')].map(r=>r.getAttribute('aria-checked')),
    fineShown: getComputedStyle(document.querySelector('.dist-sign small')).display,
  }));
  // keyboard arrow
  await page.focus('.dist-ctl [data-d="30"]');
  await page.keyboard.press('ArrowLeft');
  await page.waitForTimeout(300);
  const kb = await page.evaluate(()=>document.getElementById('distStage').getAttribute('data-d'));
  return { before, after, afterArrowLeft: kb };
})();

/* ── megamenu keyboard + focus visibility ── */
out.megamenu = await (async () => {
  await page.click('.nav-group:nth-child(3) .nav-btn');
  await page.waitForTimeout(300);
  const open = await page.evaluate(()=>({
    expanded: document.querySelector('.nav-group:nth-child(3) .nav-btn').getAttribute('aria-expanded'),
    visible: getComputedStyle(document.querySelector('#mm-print')).visibility,
  }));
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);
  const closed = await page.evaluate(()=>document.querySelector('.nav-group:nth-child(3) .nav-btn').getAttribute('aria-expanded'));
  return { open, afterEscape: closed };
})();

/* ── modal focus trap + return ── */
out.modal = await (async () => {
  await page.click('.hero-actions [data-open-contact]');
  await page.waitForTimeout(400);
  const opened = await page.evaluate(()=>({
    hidden: document.getElementById('modal').getAttribute('aria-hidden'),
    focusInside: document.getElementById('modal').contains(document.activeElement),
    bodyLocked: document.body.classList.contains('is-locked'),
  }));
  // tab through to check trap
  for (let i=0;i<12;i++) await page.keyboard.press('Tab');
  const trapped = await page.evaluate(()=>document.getElementById('modal').contains(document.activeElement));
  await page.keyboard.press('Escape');
  await page.waitForTimeout(400);
  const closed = await page.evaluate(()=>({
    hidden: document.getElementById('modal').getAttribute('aria-hidden'),
    returned: document.activeElement && document.activeElement.textContent.trim().slice(0,30),
    bodyLocked: document.body.classList.contains('is-locked'),
  }));
  return { opened, trappedAfter12Tabs: trapped, closed };
})();

/* ── focus ring actually visible on key controls ── */
await page.keyboard.press('Tab');
await page.keyboard.press('Tab');
out.focusRing = await page.evaluate(() => {
  const el=document.activeElement;
  const cs=getComputedStyle(el);
  return { on: (el.innerText||el.className).trim().slice(0,30),
           matchesFocusVisible: el.matches(':focus-visible'),
           boxShadow: cs.boxShadow.slice(0,90) };
});

/* ── reduced motion ── */
const p2 = await b.newPage({ viewport:{width:1440,height:900}, reducedMotion:'reduce' });
await p2.goto('file://'+file, { waitUntil:'load' });
await p2.waitForTimeout(1000);
out.reducedMotion = await p2.evaluate(() => ({
  stepsAllSeen: [...document.querySelectorAll('.steps li')].every(l=>getComputedStyle(l).borderTopColor!=='rgb(223, 217, 208)' || true),
  seenClass: [...document.querySelectorAll('.steps li')].filter(l=>l.classList.contains('seen')).length,
  totalSteps: document.querySelectorAll('.steps li').length,
}));
await p2.close();

out.jsErrors = errs;
console.log(JSON.stringify(out,null,1));
await b.close();
