# گزارش ممیزی مسیر — services-urban-investment-v15

**تاریخ:** ۱۰ مرداد ۱۴۰۵ · **ابزار:** `tools/route_audit.py` ·
**قرارداد مرجع:** `spec/abs-v4.2/abs_06_final_route_map_v4_2.json` +
`abs_05_final_architecture_model_v4_2.json` (۱۵۲ مسیر دقیق، ۱۰ الگو)

## حکم

**BLOCKER انتشار.** صفحهٔ `pages/services-urban-investment-v15.html` دارای
**۴۲ مسیر ثبت‌نشده** و **۴۱ نقض trailing slash** است.

این طبق تعریف خودِ بسته یک قابلیت جدید نیست، بلکه Blocker انتشار است
(`abs_15_SCOPE_FREEZE_AND_CHANGE_CONTROL_v1.md`، بند «استثنای مجاز برای
سلامت قرارداد Route»):

> ناسازگاری میان لینک‌های عمومی، Architecture Model و Route Contract یک
> قابلیت جدید نیست؛ Blocker انتشار است و باید پیش از اجرا به‌صورت نسخه
> رسمی قرارداد اصلاح و با Exact-route audit کنترل شود. **هیچ مسیر
> ثبت‌نشده‌ای نباید سرِ اجرا به تشخیص فردی ساخته شود.**

جملهٔ آخر تعیین‌کننده است: این ۴۲ مسیر با تشخیص فردی اصلاح نمی‌شوند.
تصمیم با مالک است.

### اعتبار ابزار

ابزار روی هوم مصوب اجرا شد و **دقیقاً** اعداد QC خودِ بسته را بازتولید کرد:
۳۶۶ href، ۱۲۴ مسیر داخلی یکتا، صفر ناشناخته، صفر نقض slash — برابر با
`abs_16_FINAL_QC_report_v4_2.json → route_audit`. پس اعداد این گزارش با
همان سنجه‌ای به‌دست آمده که هوم با آن PASS گرفته است.

---

## دستهٔ ۱ — فقط trailing slash · ۱۰ مورد

مکانیکی. قرارداد تغییر نمی‌کند و تصمیم مالک لازم نیست. قاعدهٔ
`trailing_slash: true` در قرارداد صریح است.

| در صفحه | درست |
|---|---|
| `/blog` | `/blog/` |
| `/consulting` | `/consulting/` |
| `/faq` | `/faq/` |
| `/portfolio` | `/portfolio/` |
| `/printing` | `/printing/` |
| `/privacy` | `/privacy/` |
| `/services` | `/services/` |
| `/services/outdoor-advertising` | `/services/outdoor-advertising/` |
| `/services/urban-investment` | `/services/urban-investment/` |
| `/terms` | `/terms/` |

## دستهٔ ۲ — نام کوتاه به‌جای مسیر متعارف · ۲۰ مورد

صفحه شکل تخت و کوتاه را به‌کار برده، ولی قرارداد همان صفحه را زیر
namespace عمیق ثبت کرده است. مقصد وجود دارد؛ نشانی‌اش فرق می‌کند.

| در صفحه | مسیر ثبت‌شده در قرارداد |
|---|---|
| `/about` | `/about-us/` |
| `/contact` | `/contact-us/` |
| `/investment` | `/services/urban-investment/` |
| `/banner-printing` | `/printing/large-format/banner-printing/` |
| `/textile-printing` | `/printing/large-format/textile-printing/` |
| `/catalog-design` | `/services/brand-collateral-design/catalog-design/` |
| `/branding` | `/services/visual-identity/` |
| `/logo-design` | `/services/visual-identity/logo-design/` |
| `/packaging-design` | `/services/packaging-label-design/` |
| `/digital-marketing` | `/services/digital-marketing/` |
| `/social-media` | `/services/social-media/` |
| `/reels-production` | `/services/social-media/instagram/reels-production/` |
| `/marketing-strategy` | `/services/strategy/marketing/` |
| `/sales-strategy` | `/services/strategy/sales/` |
| `/outdoor-advertising` | `/services/outdoor-advertising/` |
| `/product-photography` | `/services/industrial-photography/` |
| `/video-photography` | `/services/industrial-photo-video/` |
| `/tvc-teaser` | `/services/industrial-video/industrial-teasers/` |
| `/web-design` | `/services/web-design/` |
| `/structures` | `/products/outdoor-structures/` |

**دو راه، و انتخابش با مالک است:**

- **الف)** لینک‌های صفحه به مسیر متعارف اصلاح شوند. قرارداد دست‌نخورده
  می‌ماند. ارزان‌ترین راه.
- **ب)** شکل کوتاه به‌عنوان مسیر عمومی ثبت و ۳۰۱ به مسیر متعارف تعریف شود.
  قاعدهٔ `public_route_changes_require_redirect` این را می‌پذیرد، ولی
  نسخهٔ رسمی جدید قرارداد لازم دارد.

### یک مورد مبهم

`/structures/lightbox` مقصد قطعی ندارد. قرارداد دو نامزد دارد و انتخاب
معنایی است نه فنی:

- `/products/outdoor-structures/outdoor-lightboxes/` — لایت‌باکس فضای باز
- `/products/indoor-lightboxes/` — لایت‌باکس فضای داخلی

## دستهٔ ۳ — اصلاً در قرارداد نیست · ۱۱ مورد

اینجا مقصدی وجود ندارد که به آن اشاره شود. هر کدام نیازمند تصمیم مالک و
نسخهٔ رسمی جدید قرارداد است.

### ۳-الف · هفت صفحهٔ شهرستان — هیچ namespace شهری وجود ندارد

`/isfahan` · `/mobarakeh` · `/najafabad` · `/shahin-shahr` ·
`/foolad-shahr` · `/khansar` · `/zarrin-shahr`

جست‌وجو در هر ۱۳۹ صفحهٔ `sitemap_pages` نشان می‌دهد **هیچ ریشهٔ شهری
ثبت نشده است.** آنچه هست فقط `/facilities/isfahan/` و
`/facilities/tehran/` است که کارخانه‌اند نه صفحهٔ فرود شهری.

این با سند ۱۲ مستقیماً تعارض دارد. کوئری‌مپ چاپ شهرستان‌ها را «زمین برد»
معرفی کرده — «فقط یک رقیب شاهین‌شهر را در تیتر آورده. مبارکه، فولادشهر،
نجف‌آباد و زرین‌شهر دست‌نخورده‌اند.» و در جدول سنجه‌های سند ۰۹، «شهرهای
نام‌برده» حداقل ۵ تعیین شده است.

یعنی صفحه کاری را می‌کند که استراتژی خواسته، ولی قرارداد جایی برایش
باز نکرده. **این شکاف قرارداد است، نه خطای صفحه.** پیشنهاد برای تصمیم:
ثبت ریشهٔ `/cities/{city}/` یا `/isfahan/{city}/` به‌عنوان
`public_namespace_root`.

### ۳-ب · دو صفحهٔ فرود سئوی محلی

`/seo-isfahan` · `/web-design-isfahan`

قرارداد `/services/seo/` و `/services/web-design/` را دارد، ولی گونهٔ
شهری‌شان را ندارد. اگر قرار است بمانند، از نوع `LeadGenerationLanding`
ثبت شوند — این `page_type` از پیش در معماری تعریف شده است.

### ۳-پ · یک صفحهٔ محصول چاپ

`/printing/business-card`

شاخهٔ `/printing/offset/` در قرارداد فقط `catalog-printing` را دارد.
کارت ویزیت ثبت نشده، در حالی که سند ۱۲ آن را «در ورودی بخش کاغذی»
می‌داند و برایش صفحهٔ `/printing/business-card` را تجویز کرده است.

### ۳-ت · یک دارایی دانلودی

`/downloads/nemoone-gharardad-mosharekat.pdf`

قرارداد فقط دو دارایی دانلودی اعلام کرده: `/catalog/chatreghermez-services.pdf`
و `/downloads/rahnama-cheklist.pdf`.

این همان PDF نمونهٔ قرارداد مشارکت است که کارت پیش‌پرواز v12 آن را به‌عنوان
«پیشنهاد رایگان قابل‌لمس» برای قفل ۳ به حساب آورده و در «صف بعدی» ثبت کرده.
**یعنی یک قفل کنترل کیفی به فایلی تکیه کرده که نه ساخته شده و نه در
قرارداد ثبت است.**

---

## جمع‌بندی تصمیم‌های لازم

| # | تصمیم | با کیست | مسدودکنندهٔ چه چیزی |
|---|---|---|---|
| ۱ | دستهٔ ۱ اصلاح شود | نیازی به تصمیم نیست | — |
| ۲ | دستهٔ ۲: اصلاح لینک یا ثبت ۳۰۱ | مالک | انتشار صفحهٔ سرمایه‌گذاری |
| ۳ | `/structures/lightbox` به کدام مقصد | مالک | همان |
| ۴ | ریشهٔ شهری ثبت شود یا لینک‌ها حذف | مالک | استراتژی شهرستان سند ۱۲ |
| ۵ | صفحات سئوی محلی ثبت شوند یا حذف | مالک | همان |
| ۶ | `/printing/business-card` ثبت شود | مالک | صفحهٔ چاپ سند ۱۲ |
| ۷ | PDF قرارداد مشارکت ساخته و ثبت شود | مالک | قفل ۳ کارت پیش‌پرواز |

## بازتولید

```bash
tools/route_audit.py pages/services-urban-investment-v15.html
tools/route_audit.py --json pages/*.html            # خروجی ماشین‌خوان
tools/route_audit.py spec/abs-v4.2/abs_03_home_master_responsive_FINAL_v4_2.html   # کنترل
```

خروجی صفر یعنی PASS، خروجی ۱ یعنی BLOCKER — قابل استفاده در CI.
