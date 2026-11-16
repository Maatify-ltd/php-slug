# Maatify Slug — الترجمة العربية

> هذا الملف ترجمة عربية غير معيارية لملف [`README.md`](README.md).
> النسخة الإنجليزية هي الوثيقة authoritative وcanonical. عند وجود اختلاف، تكون النسخة الإنجليزية هي المرجع.

<div align="center">

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://www.maatify.dev/company-profile/maatify_logo_white.svg">
  <source media="(prefers-color-scheme: light)" srcset="https://www.maatify.dev/company-profile/maatify_logo_dark.svg">
  <img alt="Maatify.dev" src="https://www.maatify.dev/company-profile/maatify_logo_dark.svg">
</picture>

محرك مستقل لدورة حياة Slug في حزم PHP، مع حدود واضحة بين نطاق Slug وHost وURL وHTTP وSEO.

</div>

---

## حالة النشر

**الإصدار المستهدف:** `1.0.0-rc.3`، نسخة Release Candidate قبل الإصدار المستقر.

حالة النشر عند التأهيل: `1.0.0-rc.3` غير منشورة، و`1.0.0-rc.2` متاحة عبر Packagist. لا يوجد خط دعم Stable.

المرجع الحالي للعقد العام هو [`SLUG_PACKAGE_REFERENCE.md`](SLUG_PACKAGE_REFERENCE.md). تُحفظ أدلة التنفيذ في سجل GitHub PR وCI بدل تكرارها في ملف README هذا الموجّه للمستهلك.

المتطلبات والأمثلة أدناه تخص RC.3.

## ملخص الحزمة

`maatify/php-slug` مكتبة PHP مستقلة لتوليد Slug وCanonicalization والملكية المقيّدة بالنطاق وإدارة دورة الحياة والـAliases والـHistory والـResolution، مع Persistence مملوكة للحزمة عبر PDO MySQL.

## الميزات الأساسية

- **التوليد والـCanonicalization:** توليد وcanonicalization متسقان عبر Profiles مدمجة بإصدارات محددة.
- **الملكية ودورة الحياة:** Exact claiming وgenerated allocation وrelease وscope transition وatomic transfer وadoption.
- **Aliases وHistory:** Aliases نشطة ومتقاعدة مع History غير قابلة للتغيير لدورة الحياة.
- **Persistence والتزامن:** Persistence عبر PDO ومتطلبات MySQL-compatible، مع transactions وCAS وضمانات التزامن.
- **Resolution والإدارة:** Resolution وavailability واكتشاف Scopes وملخصات Scope وHistory التشغيلي وقراءات الإدارة مع pagination مشتركة.
- **الامتداد العام:** Custom Slug profiles بإصدارات ثابتة عبر Public registry path، مع ReservedSlugPolicy يملكه Host.

## المتطلبات

| المتطلب | القيمة |
|---|---|
| PHP (Composer) | `>=8.4.27 <8.5.0 \|\| >=8.5.12 <9.0.0` |
| قاعدة البيانات | PDO MySQL؛ وmysql:8.0.36 هو هدف قابلية إعادة الإنتاج في CI |
| Extensions | ext-filter, ext-intl, ext-mbstring, ext-pdo, ext-pdo_mysql |
| الحزم المباشرة | maatify/exceptions ^1.0, maatify/shared-common ^1.0, `maatify/php-persistence 1.0.0-rc.1` (اعتماد RC مؤقت) |

تتحقق CI من PHP 8.4 و8.5. يسمح Composer أيضًا بفروع PHP 8 الأحدث ضمن هذا النطاق. يلزم دليل مستقل على الأمان والتوافق لكل فرع أحدث قبل إعلان دعمه.

تحتاج الـProfiles المدمجة إلى capabilities الخاصة بالـnormalization والـtransliteration التي يوفرها `ext-intl`، وتتحقق الحزمة منها أثناء التشغيل. لا يحتاج المستهلك إلى إصدار محدد من ICU أو Unicode.

## التثبيت

بعد نشر RC.3 عبر Packagist، ثبّت الإصدار المحدد واعتماد Persistence بالأمر التالي. أبقِ قيمة `minimum-stability` في مشروعك على `stable`. يسمح `@RC` بالإصدارين المحددين للحزمتين.

~~~bash
composer require maatify/php-slug:1.0.0-rc.3@RC maatify/php-persistence:1.0.0-rc.1@RC
~~~

**الترقية من RC.2.** أوقف حركة Slug وطبّق الترحيل الإضافي `003_registry_lock_projection.sql` قبل تشغيل مسار التخزين في RC.3. اتبع [دليل ترقية قاعدة البيانات](schema/mysql/README.md#installation-and-upgrade) لاستكمال ملء البيانات والتحقق. حدّث استيرادات أنواع الترقيم من اعتماد `maatify/persistence` السابق إلى [أنواع `maatify/php-persistence`](SLUG_PACKAGE_REFERENCE.md#10-management-resolution-and-pagination).

إذا كنت تستخدم RC.2، ارجع إلى [دليل نسختها](https://github.com/Maatify-ltd/php-slug/blob/v1.0.0-rc.2/docs/guides/USAGE_GUIDE_AR.md).

## الاستخدام السريع

المسار stateless لا يحتاج إلى قاعدة بيانات أو Host framework:

~~~php
$profiles = SlugProfileRegistryFactory::createBuiltIn();
$text = SlugTextServiceFactory::create($profiles);
$slug = $text->generateFromSource(
    new SlugProfileKey('ascii-v1'),
    'Hello, World!',
);
// $slug->slug->value === 'hello-world'
~~~

في المسار persisted يحقن Host اتصال PDO وReservedSlugPolicyInterface وClockInterface في SlugEngineFactory::create(...). راجع docs/guides/USAGE_GUIDE.md للتدفق الكامل.

## Public Runtime API

يوفر الـRuntime العام مسارات فعلية لـ:

- canonicalization عبر SlugTextServiceInterface وgenerateFromSource وcanonicalizeClaim وcanonicalizeLookup.
- lifecycle ownership عبر SlugEngine وSlugLifecycleServiceInterface، بما في ذلك assignExact.
- consumer reads عبر checkAvailability وgetCurrent وresolve.
- management reads عبر SlugManagementQueryInterface، ومنها `searchScopes` و`getScopeOperationalSummary` و`searchHistory`.

لا يحل هذا الملخص محل الفهرس والعقود الكاملة في SLUG_PACKAGE_REFERENCE.md.

## الأمثلة

- examples/canonicalization.php: مثال stateless للتوليد والـcanonicalization.
- examples/custom-profile.php: مثال تسجيل واستخدام custom profile عبر Public API.
- examples/persisted-lifecycle.php: مثال persisted لدورة MySQL باستخدام Compose lifecycle canonical.

يعمل مثالا canonicalization وcustom-profile دون Docker. شغّل المثال persisted من نسخة مستودع عبر [فحص الأمثلة](CONTRIBUTING.md#4-examples-smoke-verification).

## الوثائق

- docs/guides/USAGE_GUIDE.md — الاستخدام والتكامل للمستهلك.
- examples/ — أمثلة المستهلك القابلة للتشغيل.
- SLUG_PACKAGE_REFERENCE.md — العقد العام الحالي ومرجع Public API.
- CHANGELOG.md — تاريخ التغييرات وحالة النشر.
- SECURITY.md — سياسة الأمان ومسار البلاغات.
- CONTRIBUTING.md — إعداد التطوير وأوامر التحقق.
- [`README.md`](README.md) — النسخة الإنجليزية authoritative والـcanonical.

## Transactions والتزامن

في المسار persisted تملك الحزمة transaction عندما لا يملك Host outer transaction، وتستخدم savepoint عند المشاركة في outer transaction إذا كانت capability مدعومة. تعتمد exact claims على unique Registry constraint باعتبارها authority النهائية في race، وتستخدم lifecycle mutation CAS وidempotency وفق العقد. راجع SLUG_PACKAGE_REFERENCE.md للتفاصيل المعيارية.

## الأمان وحدود الثقة

يملك Host الاتصال وتهيئة PDO ووجود الكيان وrouting وHTTP وSEO وauthorization. لا تنشئ الحزمة اتصالات مخفية ولا تستخدم Host foreign keys أو joins. لبلاغات الثغرات، راجع SECURITY.md، ولا تنشر تفاصيل ثغرة خاصة في GitHub Issues.

## الاستثناءات وتمرير الأخطاء

تستخدم الإخفاقات الدلالية وإخفاقات النطاق المعرفة داخل الحزمة تسلسل `SlugExceptionInterface` و`SlugDomainExceptionInterface` الموضح في [Package Reference](SLUG_PACKAGE_REFERENCE.md#12-exception-contract). وقد تمرر أخطاء البنية التحتية الخارجية، ومنها أخطاء PDO خارج التحويلات الدلالية الموثقة، دون تغيير وفق العقد الحالي.

## حالة الجودة

تُصان الحزمة خلف بوابات CI والجودة المهيأة للمستودع. تُحفظ أدلة التنفيذ الحالية في سجل GitHub PR وCI.

## التطوير والاختبار

لإعداد المستودع والاختبارات وبوابات الأمثلة، راجع [دليل المساهمة](CONTRIBUTING.md). يستخدم المثال persisted خدمة MySQL مؤقتة من نسخة المستودع.

## الترخيص

مرخص بموجب MIT License.

## المؤلف

Engineered by **Mohamed Abdulalim** ([@megyptm](https://github.com/megyptm))<br>
Backend Lead & Technical Architect<br>
https://www.maatify.dev
