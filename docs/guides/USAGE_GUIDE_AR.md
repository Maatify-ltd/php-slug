# Maatify Slug — دليل الاستخدام العربي

> هذا الملف ترجمة عربية غير معيارية لملف [`USAGE_GUIDE.md`](USAGE_GUIDE.md).
> النسخة الإنجليزية هي الوثيقة authoritative وcanonical، ولا ينشئ هذا الملف عقدًا تقنيًا منافسًا. عند وجود اختلاف، تكون النسخة الإنجليزية و[`SLUG_PACKAGE_REFERENCE.md`](../../SLUG_PACKAGE_REFERENCE.md) هما المرجعان.

> **الإصدار المستهدف:** `1.0.0-rc.3`، نسخة Release Candidate قبل الإصدار المستقر.

## الملاءمة ومتى تستخدم الحزمة

استخدم maatify/php-slug عندما تحتاج إلى توليد Slug أو canonicalization أو ملكية scoped مستمرة مع resolution وHistory. يناسب المسار stateless النصوص التي لا تحتاج إلى Persistence، بينما يناسب المسار persisted الحالات التي تملك فيها الحزمة lifecycle والـclaims.

## المتطلبات

- PHP (Composer): `>=8.4.27 <8.5.0 || >=8.5.12 <9.0.0`. تتحقق CI من PHP 8.4 و8.5. يسمح Composer أيضًا بفروع PHP 8 الأحدث ضمن هذا النطاق. يلزم دليل مستقل على الأمان والتوافق لكل فرع أحدث قبل إعلان دعمه.
- `ext-filter` و`ext-intl` و`ext-mbstring` و`ext-pdo` و`ext-pdo_mysql`.
- تحتاج الـProfiles المدمجة إلى capabilities الـnormalization والـtransliteration من `ext-intl`، وتتحقق الحزمة منها أثناء التشغيل. لا يوجد إصدار محدد مطلوب من ICU أو Unicode.
- يحتاج المسار stateless إلى production Composer autoload فقط.
- يحتاج المسار persisted إلى اتصال PDO متوافق مع MySQL وإلى schema الحزمة. يستخدم التحقق المحلي [خدمة MySQL المؤقتة من نسخة المستودع](../../CONTRIBUTING.md#prerequisites-for-local-development).

## التثبيت

حالة النشر عند التأهيل: `1.0.0-rc.3` غير منشورة، و`1.0.0-rc.2` متاحة عبر Packagist. لا يوجد خط دعم Stable.

بعد نشر RC.3 عبر Packagist، ثبّتها بالأمر التالي:

~~~bash
composer require maatify/php-slug:1.0.0-rc.3@RC maatify/php-persistence:1.0.0-rc.1@RC
~~~

أبقِ قيمة `minimum-stability` في المشروع على `stable`. راجع [قسم التثبيت في README](../../README_AR.md#التثبيت) لترقية RC.2 وتغيير أنواع الترقيم. إذا كنت تستخدم RC.2، اتّبع [دليل نسختها](https://github.com/Maatify-ltd/php-slug/blob/v1.0.0-rc.2/docs/guides/USAGE_GUIDE_AR.md).

## ما لا تهدف إليه الحزمة وحدود Host

لا تملك الحزمة Persistence لكيان Host أو وجوده، ولا authentication أو authorization أو routing أو URL construction أو HTTP أو SEO أو framework integration. يملك Host اتصال PDO وتهيئته، ويمرر ReservedSlugPolicyInterface وClockInterface، ويحدد هوية الكيان واحتياجات التطبيق.

لا يستخدم المستهلك جداول الحزمة أو repositories أو SQL باعتبارها Public API. راجع Package Reference للعقد الكامل وحدود الملكية.

## مسارات الإنشاء

### Canonicalization stateless

~~~php
$profiles = SlugProfileRegistryFactory::createBuiltIn();
$text = SlugTextServiceFactory::create($profiles);
$result = $text->generateFromSource(
    new SlugProfileKey('ascii-v1'),
    'Hello, World!',
);
~~~

لا ينشئ هذا المسار اتصالًا ولا يقرأ إعدادات Host. المثال القابل للتشغيل هو examples/canonicalization.php.

### Lifecycle persisted

~~~php
$profiles = SlugProfileRegistryFactory::createBuiltIn();
$engine = SlugEngineFactory::create(
    $pdo,
    $profiles,
    $reservedPolicy,
    $clock,
);
~~~

ينشئ الـHost اتصال `PDO` ويطبّق [ملفات الـSchema بالترتيب](../../schema/mysql/README.md#installation-and-upgrade)، مع إكمال ترحيل Schema 003 قبل أي قراءة أو كتابة تصل للتخزين. يوضح [المثال التنفيذي](../../examples/persisted-lifecycle.php) الإنشاء والتنظيف باستخدام بيئة الـIntegration المعتمدة.

اضبط `charset=utf8mb4` في DSN، وأبقِ `PDO::ATTR_CASE` على `PDO::CASE_NATURAL`، وفعّل `PDO::ERRMODE_EXCEPTION` وعطّل محاكاة العبارات المجهزة. أبقِ ترميز العميل والاتصال على `utf8mb4` وترميز النتائج على `utf8mb4` أو `NULL`.

يمكن للقراءات العامة، ومنها `resolve()` عند قراءة الحالة المخزّنة، استخدام حساب بصلاحية `SELECT` فقط أو معاملة `READ ONLY` مفتوحة يملكها الـHost. لا ينفّذ تحقق القراءة كتابةً ولا يفتح معاملة.

تحتاج عمليات الكتابة إلى صلاحيات كتابة وإبقاء `foreign_key_checks=ON` على جلسة `PDO` الخاصة بالـHost. تتحقق الحزمة من قدرات الكتابة بشكل مستقل، وتبقى المعاملة الخارجية تحت تحكم الـHost.

على MariaDB، إذا كان الخادم يوفّر `innodb_snapshot_isolation`، اضبطه على `OFF` لاتصال PDO قبل فتح معاملة تكتب حالة Slug. القراءات التي لا تأخذ أقفالًا تعمل مع الإعدادين. راجع [عقد PDO والمعاملات](../../SLUG_PACKAGE_REFERENCE.md#9-persistence-transactions-and-concurrency).

## خريطة القدرات

| القدرة | Public API | Walkthrough | المثال |
|---|---|---|---|
| Canonicalization / generation | SlugTextServiceInterface — generateFromSource, canonicalizeClaim, canonicalizeLookup | مسار canonicalization | examples/canonicalization.php |
| Lifecycle ownership / mutation | SlugEngine وSlugLifecycleServiceInterface — assignExact ومسار lifecycle persisted | مسار lifecycle persisted | examples/persisted-lifecycle.php |
| Consumer reads | checkAvailability, getCurrent, resolve | Consumer reads / resolution | examples/persisted-lifecycle.php |
| اكتشاف Scopes | `searchScopes(ScopeSearchCriteria)` | مسار اكتشاف Scopes | examples/persisted-lifecycle.php |
| ملخص Scope التشغيلي | `getScopeOperationalSummary(ScopeCriteria)` | مسار ملخص Scope | examples/persisted-lifecycle.php |
| History التشغيلي والنوافذ الزمنية | `searchHistory(HistorySearchCriteria)` | مسار History التشغيلي | examples/persisted-lifecycle.php |
| Public profile extension | `SlugProfileInterface` و`SlugProfileRegistryInterface::register()` | مسار profile مخصص | examples/custom-profile.php |
| Management / Operational Read | SlugManagementQueryInterface — عمليات الإدارة العامة | Management operational reads | examples/persisted-lifecycle.php |

توجه هذه الخريطة القارئ إلى الاستخدام، ولا تكرر الفهرس الكامل في Package Reference.

## مسار canonicalization

**Input**

النص Hello, World! وSlugProfileKey('ascii-v1').

**Public Call**

SlugTextServiceInterface::generateFromSource(...).

**Result**

يعاد GeneratedSlugDTO بالقيمة hello-world. يتحقق المثال من النتيجة ويفشل بـRuntimeException إذا اختلفت.

**Boundary**

لا توجد Persistence أو transaction في هذا المسار، ولا يحتاج المثال إلى Docker أو Database. قواعد Profile والـcanonicalization موثقة في Package Reference §5.

## مسار lifecycle persisted

### ملكية lifecycle والـmutation

**Input**

SlugScope(namespace: 'example', localeKey: null, contextKey: null)، وSlugProfileKey('ascii-v1')، وهوية الكيان article/example-1، وcandidate hello-world، وAuditContextDTO صالحة.

**Public Call**

SlugEngine::assignExact(new AssignExactCommand(...)).

**Result**

يعاد SlugMutationResultDTO وتكون قيمة current slug هي hello-world.

**Boundary**

يمر الاستدعاء عبر SlugEngine وlifecycle service وPersistence المملوكة للحزمة. لا يعيد Host تنفيذ claim أو uniqueness أو History.

### حذف Binding محرَّر نهائيًا

استدعِ `purgeBinding()` مباشرةً لحذف Binding نهائيًا إذا كانت حالته `RELEASED` ولا توجد له مطالبات قائمة. مرّر هويته من نوع `BindingIdentityDTO` في `$binding` ومراجعته الحالية في `$revision` كمراجعة متوقعة.

```php
use Maatify\Slug\Lifecycle\Command\PurgeBindingCommand;

$engine->purgeBinding(new PurgeBindingCommand($binding, $revision));
```

إذا لم تكن هناك معاملة خارجية، تفتح الحزمة معاملة للحذف ثم تنفّذ لها `commit` أو `rollback`. إذا كان لدى الـHost معاملة مفتوحة بالفعل على اتصال PDO المُمرَّر، يظل قرار `commit` أو `rollback` بيده. قد تظل الأقفال التي أخذتها عملية الحذف قائمة حتى تنتهي المعاملة. راجع [§9 من مرجع الحزمة](../../SLUG_PACKAGE_REFERENCE.md#9-persistence-transactions-and-concurrency).

### Consumer reads / resolution

**Input**

نفس ScopeProfileRequestDTO وsegment hello-world بعد نجاح assignment.

**Public Call**

SlugEngine::resolve(new ResolutionCriteria(...))، ويمكن استخدام checkAvailability وgetCurrent للاستعلامين الآخرين.

**Result**

يعاد SlugResolutionDTO يطابق current claim ويشير إلى article/example-1.

**Boundary**

هذه reads لا تمنح claim. checkAvailability advisory، بينما claim الفعلية يحسمها مسار mutation وunique Registry constraint.

## Management operational reads

**Input**

هوية Binding بعد assignment.

**Public Call**

SlugEngine::getBinding(new BindingCriteria($bindingIdentity)).

يمكن استخدام بقية عمليات الإدارة مع Criteria الخاصة بها عند الحاجة: getCurrent وlistAliases وgetHistory وinspectRegistry وinspectScope وsearchBindings وsearchRegistry.

**Result**

يعيد getBinding كائن BindingDTO، ويعرض المثال persisted current slug بالقيمة hello-world.

**Boundary**

هذه operational reads موجهة إلى Host أو أدوات الإدارة ولا تعيد تعريف public lifecycle contract. تعتمد pagination على الأنواع المشتركة التي يحددها Package Reference.

## اكتشاف Scopes

**Input**

يستخدم Host `ScopeSearchCriteria` لاكتشاف Scopes مع `PageRequest` ومرشحات literal اختيارية.

**Public Call**

`SlugEngine::searchScopes(...)`.

**Result**

يعيد `PageResultDTO<ScopeDTO>` حيث `total` لكل Scopes و`filtered` بعد المرشحات.

**Boundary**

هذه قراءة package-owned لا تنضم إلى Host tables أو metadata.

## ملخص Scope التشغيلي

**Input**

يستخدم Host `ScopeCriteria` مع `ScopeProfileRequestDTO` دقيق.

**Public Call**

`SlugEngine::getScopeOperationalSummary(...)`.

**Result**

يعيد `ScopeOperationalSummaryDTO` أو `null` عند غياب Scope، مع invariants الخاصة بتقسيم Binding وRegistry، وتُحسب History من persisted Scope snapshots.

**Boundary**

هذا ملخص نطاق Slug، وليس Dashboard أو metrics عامة عبر الحزم.

## History التشغيلي

**Input**

يمكن إنشاء `HistorySearchCriteria` package-wide أو لـScope دقيق، مع event type ونافذة UTC نصف مفتوحة `[fromInclusive, untilExclusive)`.

**Public Call**

`SlugEngine::searchHistory(...)`.

**Result**

يمثل `total` الحد الأساسي و`filtered` بعد المرشحات. يستخدم `occurredAt` بدقة ميكروثانية (ست خانات عشرية للثانية)، لا `originalOccurredAt`، وتنسب الأحداث إلى persisted Scope snapshot. Scope المفقود يعيد صفحة فارغة.

**Boundary**

لا يحتاج Host إلى تعداد Bindings أو SQL لبناء History التشغيلي.

## Custom Profile Extension

**Input**

تطبيق `SlugProfileInterface` بمفتاح versioned مثل `lowercase-words-v1`.

**Public Call**

يسجل عبر `SlugProfileRegistryInterface::register()` على registry المدمج، ثم يستخدم عبر `SlugTextServiceFactory` أو `SlugEngineFactory`. راجع `examples/custom-profile.php`.

**Result**

تعيد الخدمة DTOs و`Slug` العامة المعتادة.

**Boundary**

يبقى Package Reference المرجع المعياري للتوافق والتسجيل المكرر وثبات profile المحفوظ وحدود الامتداد.

## حدود Transactions والتزامن

عندما لا يملك Host outer transaction، تملك الحزمة transaction الخاصة بالعملية وتنفذ commit أو rollback وفق العقد. وعندما يمرر Host outer transaction، تشارك الحزمة فيه وتستخدم savepoint إذا كانت capability مطلوبة ومدعومة، ولا تنفذ commit أو rollback للـouter transaction.

في exact-claim race تكون unique Registry constraint هي السلطة النهائية، ويحول التعارض إلى الاستثناء الدلالي المناسب. تستخدم lifecycle mutations revision/CAS وidempotency عند توفير idempotency key. لا تغير الحزمة timezone العام؛ يستخدم المثال Clock بتوقيت UTC.

يشترك Integration وSystem وConsumer Harness والمثال persisted في Compose lifecycle canonical نفسه، ولا توجد دورة خدمة ثانية للمثال.

## الأخطاء والاستثناءات

تستخدم الحزمة SlugExceptionInterface وfamilies الاستثناءات domain، ومنها SlugAlreadyClaimedException وSlugRevisionConflictException وSlugReservedException وSlugTransactionParticipationException. يجب أن يمرر Host هذه النتائج إلى العقد المناسب بدل فحص SQL أو التقاط Throwable لإنشاء contract جديدة. راجع Package Reference §12.

## التنقل بين الأمثلة

- examples/canonicalization.php — توليد stateless.
- examples/custom-profile.php — تسجيل واستخدام profile مخصص عبر Public API.
- examples/persisted-lifecycle.php — assignment وresolution وmanagement reads على MySQL مؤقت.

لتشغيل الأمثلة الثلاثة من [نسخة المستودع](../../CONTRIBUTING.md#4-examples-smoke-verification):

~~~bash
bash tools/ci/run-gate.sh examples-smoke
~~~

تشغل هذه الـgate `canonicalization.php` و`custom-profile.php` مباشرة، ثم تشغل `persisted-lifecycle.php` عبر Compose lifecycle canonical. لا يعمل المثال persisted كعملية standalone خارج هذه البيئة لأنه يحتاج إلى SLUG_TEST_DB_*.

## وثائق إضافية

- Package Reference — العقد المعياري وPublic API.
- CONTRIBUTING.md — إعداد التطوير وquality gates.
- CHANGELOG.md — تاريخ التغييرات وحالة النشر.
- SECURITY.md — سياسة الأمان والحدود.
- [`USAGE_GUIDE.md`](USAGE_GUIDE.md) — النسخة الإنجليزية authoritative والـcanonical.
