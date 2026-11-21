# Official papers: inventory and mapping to the web

Prepared 21 November 2026 on branch `official-papers`. This is an analysis document only. No application code, schema or board was changed.

- **Source:** the official papers folder the owner provided on 21 Nov 2026 (`002MP_SYR_FormalPapers`). It has 242 files: 143 Word and 99 PDF.
- **Privacy:** the files themselves are not in the repository. Everything here was taken from extracted text and page images, which were viewed outside the repository.
- **What is left out:** names, phone numbers, addresses and the sample identities typed into the Word templates.
- **Machine-readable copy:** [`inventory.json`](inventory.json) lists every file and every form.

## 1. The short version

- **33 distinct papers.** The 242 files reduce to 33 papers. Three of them are not forms to fill:
  - the "required documents" handout,
  - the procedures sheet (`مراحل`),
  - the photo-policy explainer (`RC_Info`).
- **City copies are the same paper.** In every city copy the body is word-for-word identical. Only the letterhead footer changes (city address and mobile number). In the Word templates the sample place name also changes (حلب / حمص / دمشق / اللاذقية / ادلب).
  - On the beneficiary card, Homs prints different payment hours.
  - The Al Wadi handout has its own document list and hours.
  - Everything else is identical.
- **Non-Syrian copies change only the beneficiary's identity block.** The folder `لغير السوريين` holds the non-Syrian copies of 10 legal papers. For the car sale and sale-and-purchase contracts the copy is identical to the Syrian one. In them the labelled identity lines (بن / والدته / تولد / قيد / رقم وطني / منح) become one to three empty lines, so a passport or residence identity can be written freely. The clauses do not change.
- **The web prints one of them today.** The only official paper the web produces is the M4.7 checklist print, and the checklist itself is not in this folder.
  - `resources/views/applications/print.blade.php` exists but is not routed. It belongs to the old layer in `routes/legacy-web.php`.
  - No paper in the folder is produced by the web yet.
- **None of the files can be filled automatically as they are.**
  - Every PDF is a scan or outlined text, with no text layer.
  - Every Word file is one full-page picture of the paper with floating text boxes laid over it.
- **Recommended format:** recreate each paper as an HTML print view, in the M4.7 checklist print style, with a per-city letterhead. See section 6.
- **Most fields already have a column.** The application papers, the meeting papers, the beneficiary card and the special case are almost fully covered by the approved v1.3 schema.
- **The main gaps are in the legal and financial papers:**
  - the centre's representative identity,
  - the ID-card issue (منح),
  - the deed terms,
  - the contracts' parties and subjects,
  - the disbursement request, equipment returns and training attendance.

  Section 7 lists them as proposals for the owner's approval. The schema was not changed.

## 2. What the folder holds

| Folder | Files | Notes |
|---|---|---|
| `001 ALP` | 36 | the full set |
| `002 HMS` | 35 | the full set, without the generic `SYR` promissory note |
| `003 DMS` | 36 | the full set. File names use `DMS`; the web's city code is `DAM` |
| `004 LAT` | 39 | the full set, plus three extra copies (see below) |
| `013 IDL` | 25 | Idlib. It reuses ALP copies, so it prints the **Aleppo** address. The web has no Idlib city |
| `Al Wadi` | 21 | reuses HMS copies (file names say `HMS`), apart from its own handout |
| `لغير السوريين` | 50 | non-Syrian copies of 10 legal papers, one per city (ALP, DMS, HMS, IDL, LAT) |

The three extra LAT copies:
- the generic `002_MP_SYR_صك براءة ذمة.docx`,
- a second car-sale contract whose file name has a stray kasra (`002_MP_ِLAT_…`),
- the only copy of the older individual questionnaire, `002 MP_LAT_استبيان لقاء أصحاب المهن.pdf`.

**Codes in the file names.** No paper prints a programme code; the codes appear only in file names.
- `002MP` / `002 MP_` / `002_MP_`: the Micro Projects papers.
- `001HC`: the Hope Center-wide papers (meetings, training, photo policy).
- `CP_`: the Caritas Polska bilingual consents.

**Missing copies:**
- IDL has no preliminary form, special case, audio consent, experience test, price quote list or procedures sheet. Its beneficiary card, equipment return and equipment receipt pledge are the ALP files.
- Al Wadi has only the legal papers, the meeting and training papers, the disbursement request and its handout.

## 3. The papers in the order of the procedure

The procedures sheet `002MP_SYR_مراحل` is identical in every city. Its column `المرفق` numbers the attachments of a beneficiary's file, and that numbering is the "paper order". The table below follows it.

The sheet only shows the numbers next to its steps; it has no list matching numbers to papers. The match below is our reading of those steps and needs the owner's confirmation.

| No. | Paper (procedure step) | In the folder | Our id |
|---|---|---|---|
| 1 | Papers handed to the applicant at reception | `ProjectsNeeds` handout | F01 |
| 2 | "Zero" form, for unsupported project types, closed registration or a new centre | `استمارة تسجيل أوليّة` (printed title "الاستمارة الأوليّة للمشروع") | F02 |
| 3 | Official unified application form | `استمارة المشروع` | F03 |
| 4 | Audio-recording consent | `موافقة تسجيل صوتي` | F04 |
| 5 | Special case | `Special Case` | F05 |
| 6 | Visit guide (`دليل الزيارة`) | **missing** | — |
| 7 | Needs scale (`مقياس الحاجة`) | **missing** (the sheet says it is calculated by the program) | — |
| 8 | Professional test | `نموذج فحص الخبرة` | F06 |
| 9 | Checklist with both committees' opinions | not in the folder; **the web prints it already (M4.7)** | — |
| 9–10 | Purchase request, signed by the beneficiary | `طلب مشتريات` | F09 |
| 11 | Promissory note | `سند أمانة` | F10 |
| 12 | Photo consent (Caritas and other donors) | `CP_consent_…` and `RC_Sign` | F24, F25, F27 |
| 13–17 | Acknowledgement deeds (tools, vehicles, solar) | five `صك إقرار …` papers | F11–F15 |
| 18 | Disbursement request | `طلب صرف` | F19 |
| 19 | Sale and purchase contract, for a used machine with no invoice | `عقد بيع وشراء` | F17 |
| 20 | Receipt voucher (`وصل الاستلام`), made by accounting | **missing** | — |
| 21–22 | Equipment receipt pledge and the payments card | `تعهد استلام المعدات المطلوبة`, `Beneficiary Card` | F20, F21 |
| 23–24 | Opening visit (24 = vehicle projects) | **missing**; visits come from Kobo (M4.14) | — |
| 25–29 | Monitoring visits 1–5 | **missing**; Kobo | — |
| 30 | Clearance deed and the memorial shield | `صك براءة ذمة` | F23 |
| 31 | Critical cases guide | **missing** | — |

These papers are in the folder but have no number in the procedure:
- the price quote list (F08),
- the car sale contract (F16),
- the partnership contract (F18),
- the equipment return (F22),
- the photo-policy explainer (F26),
- the meeting and training papers (F28–F33).

## 4. Inventory by distinct paper

How to read the columns:
- **Cities:** where a copy exists. "Same" means the body is identical and only the footer differs.
- **Format:** the source format.
- **Module:** the owning module in `docs/planning/rebuild-plan-2026-11.md`.
- **Web today:** whether the web produces the paper now.

### A. Registration and study

| Id | Paper | Files | Cities | Format | Module | Web today |
|---|---|---|---|---|---|---|
| F01 | الأوراق المطلوبة (handout) | 5 | ALP, HMS, DMS, LAT and Al Wadi each have their own. All share 8 items except **Al Wadi, which has 9** (it asks for price quotes and a place for livestock). Hours differ by city | PDF, 1 page of cut slips | M4.18 (static) | no |
| F02 | استمارة تسجيل أوليّة | 4 | ALP, HMS, DMS, LAT: same | PDF, 2 pages | M4.5 / M4.18 | no (entry yes, print no) |
| F03 | استمارة المشروع | 5 | ALP = IDL (byte-identical); HMS, DMS, LAT: same | PDF, 5 pages | M4.5 / M4.18 | no (entry yes, print no) |
| F04 | موافقة تسجيل صوتي | 4 | ALP, HMS, DMS, LAT: same apart from the printed city name | PDF, 1 page | M4.5 | no |
| F05 | Special Case | 4 | ALP, HMS, DMS, LAT: same | PDF, 1 page | M4.13 | no |
| F06 | نموذج فحـص الخبــرة | 4 | ALP, HMS, DMS, LAT: same | PDF, 1 page | M4.7 | no |
| F07 | الإجراءات المتبعة (مراحل) | 8 | one sheet, Aleppo letterhead in every city | Word + PDF, 5 pages | reference only | n/a |

### B. Financing, legal and purchasing

| Id | Paper | Files | Cities | Format | Module | Web today |
|---|---|---|---|---|---|---|
| F08 | قائمة عرض أسعار | 4 | ALP, HMS, DMS, LAT: same | Word (picture) | M4.10 | no |
| F09 | طلب مشتريات | 5 | ALP = IDL; HMS, DMS, LAT: same | Word: picture header plus a real Word table | M4.10 | no |
| F10 | سند أمانة | 14 | One unbranded picture shared by all cities. There is also a generic `SYR` copy (ALP, DMS, LAT; one amount line instead of two) and a non-Syrian copy | Word (picture) | M4.9 | no |
| F11 | صك إقرار وتعهد (العدد والآلات) | 11 | ALP = IDL; HMS, DMS, LAT: same. Non-Syrian copy for each | Word (picture) | M4.9 | no |
| F12 | صك إقرار وتعهد (العدد والآلات زراعي) | 11 | as F11 | Word (picture) | M4.9 | no |
| F13 | صك إقرار وتعهد (طاقة شمسية) | 11 | as F11 | Word (picture) | M4.9 | no |
| F14 | صك إقرار وتعهد (طاقة شمسية زراعي) | 11 | as F11 | Word (picture) | M4.9 | no |
| F15 | صك إقرار وتعهد مركبات | 11 | as F11 | Word (picture) | M4.9 | no |
| F16 | عقد بيع سيارة قطعي | 12 | One unbranded picture for all cities. The non-Syrian copy is identical. LAT has a second, different copy | Word (picture) | M4.10 | no |
| F17 | عقد بيع وشراء | 11 | each city has its own footer; the non-Syrian copy is identical | Word (picture) | M4.10 | no |
| F18 | عقد شركة محاصة | 11 | ALP = DMS = IDL (Aleppo footer); HMS, LAT have their own; non-Syrian copy | Word (picture), 2 pages | M4.9 | no |
| F19 | طلب صرف | 6 | ALP = IDL; HMS = Al Wadi; DMS, LAT: same. **Each file carries two backgrounds**, an old and a new layout | Word (picture) | M4.10 | no |
| F20 | تعهد استلام المعدات المطلوبة | 9 | ALP = IDL; HMS, DMS, LAT: same | PDF + Word (picture, no text boxes) | M4.10 | no |
| F21 | Beneficiary Card (كرت الدفعات) | 5 | ALP = IDL; HMS (different payment hours), DMS, LAT | PDF, 2 pages (4 fold cards per sheet) | M4.9 | no |
| F22 | مرتجع معدات وبضائع | 6 | ALP = IDL; HMS = Al Wadi; DMS, LAT: same | PDF, 1 page | M4.10 | no |
| F23 | صك براءة ذمة | 12 | ALP = IDL; HMS = Al Wadi; DMS, LAT: same. Non-Syrian copy for each. LAT also has a generic `SYR` copy | Word (picture) | M4.8 (the "finished" state) | no |

### C. Consents

| Id | Paper | Files | Cities | Format | Module | Web today |
|---|---|---|---|---|---|---|
| F24 | الموافقة على استخدام الصورة الشخصية والصوت (adult, Caritas Polska) | 5 | identical in 5 cities | PDF, Arabic and Polish | M4.11 (signed scan) | no |
| F25 | الموافقة على استخدام الصورة … والفيديو (child, Caritas Polska) | 5 | identical | PDF, Arabic and Polish | M4.11 | no |
| F26 | الوثيقة التوضيحية لسياسة استخدام الصور (RC_Info) | 6 | identical in 6 folders | PDF, 2 pages, explainer | static | no |
| F27 | إقرار بالموافقة عن علم لاستخدام الصور (RC_Sign) | 6 | identical | PDF, 1 page | M4.11 | no |

### D. Meetings and training (the `001HC` papers)

| Id | Paper | Files | Cities | Format | Module | Web today |
|---|---|---|---|---|---|---|
| F28 | استبيان لقاء أصحاب المهن (group, one per profession per meeting) | 6 | ALP = IDL; HMS = Al Wadi; DMS, LAT: same | PDF, 8 pages | M4.15 | no (desktop and Kobo entry only) |
| F29 | استبيان لقاء أصحاب المهن (older, one per person) | 1 | LAT only | PDF, 4 pages | M4.15 (probably retired) | no |
| F30 | سجل حضور لقاء المهن | 6 | same in all | PDF, 1 page | M4.15 | no |
| F31 | محضر اجتماع لقاء المهن | 6 | same in all | PDF, 2 pages | M4.15 | no |
| F32 | سجل حضور التدريب | 6 | same in all | PDF, 1 page | no module yet | no |
| F33 | جدول حضور دورة إدارة المشاريع | 11 | 6 PDF copies (8 rows, 9 sessions) and 5 Word landscape copies (10 rows, real Word table) | PDF + Word | M4.7 (management training) | no |

## 5. Fields and where they come from

"✓" means an approved v1.3 column fills the blank. "**gap**" means no column holds it.

Two things are left out of the tables:
- Printed letterhead and wording come from the per-city print layout, not the database.
- Signature lines are left empty.

### F01 الأوراق المطلوبة
Static text: the document list and the intake days, hours, address and phone per city. Nothing is filled. The list matches `attachment_types` category "ثبوتيات شخصية".

### F02 استمارة تسجيل أوليّة and F03 استمارة المشروع
F02 is a subset of F03. It has no photo, story, goals, approvals, education, courses, energy, budget, losses, illnesses, assets, debts, aid or declaration. It prints "هذه الاستمارة أوليّة وليست تسجيل معتمد". The web's `PaperOrder` already orders the entry screens by this paper.

| Paper blank | Column |
|---|---|
| رقم المشروع | `projects.project_number` ✓ (shown with the city code, M1.2) |
| الاسم الثلاثي، الاسم، الكنية، الجنس | `people.first_name`, `last_name`, `father_name`, `sex` ✓ |
| الاسم والكنية (EN) | `people.full_name_en` ✓ |
| الصفة (مستفيد / شريك) | `project_applicants.role` ✓ |
| اسم الأب، اسم الأم، القيد/الأمانة، الرقم الوطني، المواليد | `people.father_name`, `mother_name`, `registration_place`, `national_number`, `birth_date` (year) ✓ |
| الحالة الاجتماعية، الطائفة، الهيئة الوسيطة، الكاهن | `people.marital_status_id`, `parish_id`, `intermediary`, `priest_id`, `second_priest_id` ✓ |
| رقم الجوال، الأرضي، جوال أحد أفراد العائلة | `people.mobile`, `home_phone`, `mobile_2` ✓ |
| المنطقة (القرية)، العنوان التفصيلي | `people.street_id`, `home_address` ✓ |
| ملكية المنزل، ملاحظات | `people.home_ownership_id`, `home_state_note` ✓ |
| خدمة العلم، مدة الخدمة، تاريخ التسريح، خلال الحرب | `people.military_service`, `military_period`, `military_service_date`, `military_in_war` ✓ |
| المهنة، وصف المشروع، نوع المشروع، طبيعة المشروع | `projects.career_id`, `name`, `project_type_id`, `project_subtype_id` ✓ (the desktop shows "طبيعة المشروع" from SubType) |
| تاريخ الطلب، نوع التمويل، المبلغ المطلوب | `projects.request_date`, `fund_type_id`, `requested_amount` ✓ |
| Photo box | person photo in `attachments` (type "صورة شخصية", M4.11) ✓ |
| قصة المستفيد، هدف المستفيد، كيف سيحقق، ملاحظات مقدم الطلب | `project_applicants.loss_story`, `goal`, `goal_how`, `other_notes` ✓ |
| 14 goal tick boxes | `person_goals` → `goals` ✓ |
| الخبرات الحالية / السابقة (يعمل / لا يعمل) and their tables | `project_applicants.has_work` and `person_work_experiences` (`occupation_id`, `work_type_id`, `description`, `begin_text`, `end_text`, `place_state`, `place`, `contact`, `salary`, `leaving_reason`) ✓ |
| عدد سنوات الخبرة | `project_applicants.work_years` ✓ |
| التحصيل العلمي (متعلم / غير متعلم) and its table | `has_education` and `person_educations` ✓ |
| الدورات (يوجد / لا يوجد) and its table | `has_courses` and `person_courses` (`project_subcategory_id`, `name`, `place`, `donor_text`, `date_text`) ✓ |
| ملكية عقار المشروع، العنوان، ملاحظات | `projects.place_type_id`, `place_address`, `place_note` ✓ |
| مصادر الطاقة في المشروع / في المنزل | `project_power_sources` / `person_power_sources` ✓ |
| الاستطاعة المطلوبة (أمبير / واط) | `projects.amper_number`, `amper_type` ✓ |
| موافقات 1–5 | `projects.agreement_1` … `agreement_5` ✓ |
| مقترح ميزانية (المادة، نوعها، كميتها، سعر القطعة) | `project_materials` (`name`, `comments`, `quantity`, `price`) ✓. "نوعها" has no column of its own; it goes into `comments` |
| عدد أفراد العائلة، المقيمين، رقم دفتر العائلة | `people.family_size`, `household_size`, `families.family_number` ✓ |
| هل تقيم معك عائلة أخرى، من هي | `project_applicants.lives_with_family`, `lives_with_family_note` ✓ |
| Family table (الاسم … الرقم الوطني، المواليد، حالة الإقامة، التحصيل، الدرجة، يعمل) | `family_members` + the member's `people` row (`person_state_id` is حالة الإقامة) + `education_level_id`, `education_specialty_id`, `has_work` ✓ |
| طبيعة عمل عائلة المستفيد (المهنة، طبيعة العمل، **الدخل**) | `family_members.work_name` ✓ for the job. **Gap:** work type and income have no column (G8) |
| الخسارات | `has_loss` and `person_losses` ✓ |
| الحالات المرضية | `has_illness` and `person_illnesses` ✓ |
| أملاك المستفيد | `has_assets` and `person_assets` ✓ |
| الاستفادة من جهة أخرى | `has_aid_sources` and `person_aid_sources` ✓ |
| ديون، المبلغ، السبب | `has_debts`, `debts_amount`, `debts_note` ✓ |
| مصادر المساعدة | `has_help` and `person_aid_received` ✓ |
| معلومات إضافية | `projects.profit_description` ✓ (desktop group "معلومات إضافية") |
| Declaration name | the beneficiary's name ✓ |
| توقيع مسجّل الطلب (printed twice) | the names can come from `projects.paper_registrar_account_id` and `program_registrar_account_id` ✓ |

### F04 موافقة تسجيل صوتي
Signer name (`people`) ✓, city name (`cities.name_ar`) ✓, date (the print date or the request date) ✓. Keep the signed scan in `attachments` under type "عقود وموافقات" ✓.

### F05 Special Case
| Blank | Column |
|---|---|
| الاسم الثلاثي، رقم الموبايل، رقم المشروع | `people` and `projects` ✓ |
| مسجّل الطلب | `special_cases.account_id` ✓ |
| نوع المراجعة (مقابلة / هاتف) | `special_cases.special_case_type_id` ✓ (the seeded list is exactly هاتفية / مقابلة) |
| سبب المراجعة، تفاصيل المراجعة، الإجراء المتّبع، التاريخ | `reason`, `description`, `action`, `case_date` ✓ |

Fully covered.

### F06 نموذج فحص الخبرة
| Blank | Column |
|---|---|
| المتقدّم، رقم المشروع | ✓ |
| التاريخ، المهني المسؤول | `checklists.professional_test_date`, `professional_tester_id` ✓ |
| مجال | `projects.career_id` ✓ |
| تقييم مراحل العمل: 9 rows, each stage with قادر / غير قادر / ملاحظات | **gap** (G7) |
| مستوى الخبرة (لا يوجد / جيدة) | `checklists.professional_test_result` ✓ (free text) |
| ملاحظات مقدم الطلب، المهني، إضافية | only one note column, `professional_test_note` ✓. **Gap** for three separate notes (G7) |
| اسم المتطوّع | **gap** (G7) |

### F08 قائمة عرض أسعار
- **Supplier name and fill date:** **gap** (G10).
- **"Offered to" name:** the beneficiary ✓.
- **Items table:** can be pre-filled from `project_materials` ✓, or left for the trader to fill by hand, as the paper intends.
- **Trader's name and stamp:** done by hand.

### F09 طلب مشتريات
- **Project number, beneficiary name, phone, trade, area:** `projects`, `people.mobile`, `careers`, `streets` ✓.
- **Items table (المادة، النوع، العدد، حالة المادة):** `project_materials` ✓. **Gap:** "حالة المادة" (new or used) has no column (G10).
- **Undertakings and four signature lines:** printed text.

### F10 سند أمانة
| Blank | Column |
|---|---|
| Beneficiary: name, father, mother, تولد, قيد, رقم وطني | `people` ✓ |
| Beneficiary: منح (ID-card issue) | **gap** (G2) |
| The person who hands over the money (the centre's side): full identity | **gap** (G1) |
| مبلغ وقدره (figures and words, with currency) | the sum of the project's invoices in `purchases`, or `loans.amount` ✓. The procedure says the note must equal the invoice total. The amount in words is generated |
| المكان المختار للتبليغ | `people.home_address` ✓ (to confirm) |
| Place and date | `cities.name_ar` and the print date ✓ |
| Two witnesses | blank lines |

### F11–F15 صكوك الإقرار والتعهد
Shared blanks:

| Blank | Column |
|---|---|
| Beneficiary identity | `people` ✓; منح is a **gap** (G2) |
| "السيد ……" (the centre's named representative, several times) | **gap** (G1) |
| Monthly instalment (figures and words) | `loans.installment_amount` ✓ |
| Grace period ("بعد … من شراء") | derived from `loans.taken_on` and the first `loan_installments.due_on` ✓ (to confirm) |
| نسبة الاسترداد (sample 100%) | `loans.rate` or `returned_amount / amount` ✓ (which one to confirm) |

Paper-specific blanks:

| Paper | Blank | Column |
|---|---|---|
| F12 and F14 (agricultural) | Seasonal instalment: start date | **gap** (G3) |
| F12 and F14 (agricultural) | Value of agricultural medicines; month and year of delivery | **gap** (G3) |
| F13 and F14 (solar) | منظومة الطاقة (system description) | the solar items in `purchases` or `project_materials` ✓, or **gap** if a free description is wanted (G3) |
| F15 (vehicles) | المركبة (vehicle description: make, model, plate) | `purchases.item_name`, `brand` ✓. **Gap:** plate and chassis numbers (G3) |

### F16 عقد بيع سيارة قطعي and F17 عقد بيع وشراء
- **Parties:** the seller, a free outside party, is a **gap** (G4). The buyer is the centre's representative (G1) or the beneficiary.
- **Contract subject:** car or device description, **gap** (G4).
- **Price:** `purchases.price` ✓.
- **Hand-over date and time; chosen address:** **gap** (G4).
- **Court city:** `cities.name_ar` ✓.

### F18 عقد شركة محاصة
- **First party:** the centre's representative, **gap** (G1).
- **Second party:** the beneficiary, `people` ✓; منح is a **gap** (G2).
- **The beneficiary's trade:** `careers` ✓.
- **Company purpose, financed equipment:** `projects.name` and `purchases` ✓.
- **Profit-distribution period; duration, start and end:** **gap** (G4).
- **Court city:** ✓.
- `projects.partnership` already marks partnership projects.

### F19 طلب صرف
The new layout (the second picture, with the 10% notes) is assumed to be current.

| Blank | Column |
|---|---|
| تاريخ الطلب، رقم الطلب | **gap** (G5) |
| Beneficiary name (AR and EN), project, project number, mobile, national number | ✓ |
| الجهة المانحة | `projects.donor_id` ✓ |
| تاريخ أول قسط | the first `loan_installments.due_on` ✓ |
| المبلغ المطلوب | `projects.requested_amount` ✓ |
| مبلغ الحجز | **gap** (G5) |
| النسبة، قيمة القسط | `loans.rate`, `installment_amount` ✓ |
| المبلغ الإضافي (amount, reason) | **gap** (G5) |
| Invoice table (amount, number, description, notes) | `purchases` (`price`, `invoice_number`, `item_name`) ✓ |
| مجموع الفواتير | computed ✓ |

### F20 تعهد استلام المعدات المطلوبة
- **The boxed list "above":** the project's `purchases` and traders ✓.
- **Beneficiary name:** ✓.
- **Date:** the date the receipt voucher was signed. It could use `loans.taken_on`, which is to be confirmed. Otherwise the print date.
- **Signed scan:** kept in `attachments` as "تعهدات استلام - مشتريات" ✓.

### F21 Beneficiary Card (كرت الدفعات)
| Blank | Column |
|---|---|
| اسم المستفيد، رقم المشروع | ✓ |
| الجهة الداعمة | `projects.donor_id` ✓ |
| رقم الإيصال | `loans.receipt_number` ✓ |
| Payment hours, days off, address, phones | the per-city print layout |
| Back of the card: 12 unlabelled tick-and-line slots | we assume these are the instalments, filled by hand at each payment. They could be pre-printed with the due dates from `loan_installments` ✓ (to confirm) |

### F22 مرتجع معدات وبضائع
- **Beneficiary, project type, project number:** ✓.
- **Receipt date of the return:** **gap** (G6).
- **Items table:**
  - type (النوع) and brand (الماركة): `purchases.item_name`, `brand` ✓,
  - serial number, technical condition, notes: **gap** (G6).
- **Item count:** computed.

### F23 صك براءة ذمة
- **The centre's representative identity:** **gap** (G1).
- **Beneficiary identity:** ✓; منح is a **gap** (G2).
- **Loan amount (figures and words):** `loans.amount` ✓.
- **City and date:** ✓.
- **Timing:** printed when the project reaches the "finished" state (M4.8). The procedure says not before two years have passed, even if the loan is fully paid.

### F24, F25, F27 consents
- **Names:** the beneficiary, or for a child the guardian and the child, come from `people` and `family_members` ✓.
- **Project or event name (RC_Sign):** ✓.
- **Date and place:** ✓.
- **RC_Sign choices** (three acknowledgements, four agree/disagree, one anonymity wish): no columns. We recommend keeping only the signed scan in `attachments` (type "عقود وموافقات") and not recording the choices as data. This is a question for the owner (Q5).
- **F24 and F25** name Caritas Polska. Print them only for that donor's projects (Q6).

### F28 استبيان لقاء أصحاب المهن (group)
This paper fits the approved meeting tables almost exactly:

| Paper part | Table |
|---|---|
| Header: المهنة، التاريخ، عدد الحضور | `profession_meetings` (`career_id` / `profession_meeting_careers`, `meeting_date`, `attendance_count`) ✓ |
| Q1–Q4, Q7, Q11, Q13, Q17 (tick boxes, ranking, "other") | `profession_meeting_option_answers` (`is_selected`, `rank_value`, `other_text`) over `profession_options` ✓ |
| Q5, Q14, Q15 (lists and free text) | `profession_meeting_answers.value_text` ✓ |
| Q6 (competition and reason) | option answers and `other_text` ✓ |
| Q8 (season from, to, reason) | `profession_meeting_seasons` ✓ |
| Q9 (union / شيوخ الكار) | option answers and `other_text` ✓ |
| Q10 (brands) | `profession_meeting_brands` ✓ |
| Q12 (equipment per speciality) | `profession_meeting_equipment` (`specialization`, `equipment`) ✓. This explains why its three columns are all headed "الاختصاص" |
| Q16 (challenge, count, solutions) | `profession_meeting_challenges` (`challenge`, `challenge_count`, `suggested_solution`) ✓ |
| Q18–Q21 (project number, name, speciality) | `profession_meeting_participants` with `profession_question_id` ✓ |
| Page 8, spiritual section | the paper itself says it must not be recorded. **Nothing is stored**; print the text only |

Whether the question wording in `profession_questions` matches this 8-page version has to be checked in M4.15, because the desktop list may follow the older F29.

### F29 older individual questionnaire (LAT)
It is per person: name, age, gender, home or shop, form number, many yes/no questions. Its content is a subset of F28's themes. We assume it is retired (Q9).

### F30 سجل حضور لقاء المهن and F31 محضر اجتماع لقاء المهن
| Blank | Column |
|---|---|
| المهنة، التاريخ، المكان | `profession_meetings` ✓ |
| توقيت البدء / الانتهاء | **gap** (G9) |
| عدد المدعوين / الحضور / الغياب | `invited_count`, `attendance_count`, `absence_count` ✓ |
| Attendee names and speciality | `profession_meeting_attendees` ✓ |
| Attendee gender and age (minutes) | from the linked `people` row when `person_id` is set ✓. **Gap** for attendees not linked to a person |
| Axes 1–6 notes | `profession_meeting_minutes` per `profession_section` ✓, and `minutes_notes` ✓ |

### F32 سجل حضور التدريب and F33 جدول حضور دورة إدارة المشاريع
| Blank | Column |
|---|---|
| Training name, trainer, date, place, start/end time | per beneficiary only: `checklists.management_trainer`, `management_training_place`, `management_training_date`. **Gap:** there is no table for a training or a course with sessions and attendance (G9) |
| Beneficiary rows (project number, name) | can be pre-filled from the selected projects ✓ |
| Per-session subject, date and attendance marks | **gap** (G9) |

## 6. Output format per paper

The recommendation is an **HTML print view for every paper**, built on the M4.7 checklist print. Reasons:

- **The sources cannot be filled reliably.** The Word sources are a picture plus floating boxes; the PDFs have no text layer.
- **The papers are easy to rebuild.** Recreating each one in HTML with its exact wording is straightforward, and the result prints the same in every browser.
- **City differences become data.** They are only the footer, the place name and, on two papers, the hours and the document list, so one template per paper and a per-city letterhead cover everything.
- **No new library.** The project has no Word library (`composer.json` has no PhpWord). The M4.18 Word templates would add `phpoffice/phpword`.

**A Word file is useful in two cases:**
- the meeting minutes and the questionnaire (F28, F31), which the plan's M4.18 lists as Word templates;
- the three contracts (F16–F18), if the owner wants staff to edit the parties and subject before printing.

For these we propose an optional "download as Word" next to the print view, built from the same data. Everything else is print-only.

| Paper | Output | Pre-filled from data |
|---|---|---|
| F01, F26 | static HTML print (per city) | no |
| F02, F03 | HTML print in paper order | yes, fully |
| F04, F24, F25, F27 | HTML print | names, city and date |
| F05, F06 | HTML print | yes |
| F08, F09, F20, F22 | HTML print | items from materials or purchases |
| F10–F15, F23 | HTML print, Syrian and non-Syrian layout | yes, once G1 and G2 are decided |
| F16–F18 | HTML print, plus optional Word | partly (G4) |
| F19, F21 | HTML print (F21 as a 4-card sheet) | yes |
| F28, F31 | HTML print, plus Word (M4.18) | yes, from the meeting record |
| F30, F32, F33 | HTML print (sign-in sheets) | the attendee or beneficiary list |

## 7. Gaps that need a schema change (proposals for the owner)

These are **proposals only**. Each needs the owner's approval as a v1.4 addendum before any code. The approved v1.3 schema is not changed.

| Gap | Papers | Proposal | Alternative without a schema change |
|---|---|---|---|
| **G1. The centre's representative** (full identity: name, father, mother, birth, registry, national number, ID issue) | F10, F11–F15, F16, F18, F23 | A per-city table of signatories by role (for example "legal representative"), with a validity period | Type it at print time without storing it (it would be retyped for every paper) |
| **G2. ID-card issue (منح) and identity kind** (Syrian national number, or passport/residence for non-Syrians) | F10–F15, F18, F23 | Add `id_issue_text` and an identity kind (or nationality) on `people`. The kind picks the Syrian or non-Syrian layout | The user picks the layout and types منح at print time |
| **G3. Deed terms** (grace period if not derived, agricultural season start, medicines amount, month and year, solar system description, vehicle plate and chassis) | F11–F15 | A small `project_deed_terms` table (one row per project and deed) | Type at print time |
| **G4. Contract details** (seller and other parties as free text, subject, hand-over date and time, chosen address, partnership period and duration) | F16–F18 | A `project_contracts` table (kind, parties, subject, price, dates) | Fill at print time and keep the signed scan as an attachment (**recommended to start with**) |
| **G5. Disbursement request** (request number and date, hold amount, extra amount and reason) | F19 | A `disbursement_requests` table per project | Type at print time |
| **G6. Equipment return** (return date; per item: serial number, condition, notes) | F22 | `equipment_returns` with items linked to `purchases`, plus `serial_number` on `purchases` | Print blank with the purchases list; keep the signed scan |
| **G7. Experience test detail** (9 stages with capable/not and a note, three notes, volunteer) | F06 | `professional_test_stages` linked to the checklist, and two note columns | Print the stages blank and keep the signed scan |
| **G8. Family member work type and income** | F03 page 4 | `work_type_id` and `income` on `family_members` | Leave blank on print (the desktop does not store them either) |
| **G9. Trainings and meeting times** (training or course, sessions, attendance; meeting start and end time) | F30, F32, F33 | `trainings`, `training_sessions` and `training_attendance`, plus `starts_at` and `ends_at` on `profession_meetings` | Print sign-in sheets with the names pre-filled; keep scans |
| **G10. Price quote and material condition** (supplier, fill date; new or used) | F08, F09 | `condition` on `project_materials`; quotes kept as attachments | Print blank lines |

## 8. Proposed implementation order

**Step 0: one shared print base**
- one print layout with a per-city letterhead (logo, footer address and phone, page numbers) and the amount-in-words helper;
- one "papers" tab on the project page that lists each paper in procedure order.

This sits with M4.18 but can be done first, because every module below needs it.

**Wave 1: data already exists (M4.3–M4.7 done)**
- F03 استمارة المشروع and F02 الاستمارة الأولية (prefilled print in paper order);
- F04 audio consent and F27 / F24 / F25 consents;
- F06 experience test (without the stages table until G7 is decided);
- F01 and F26 static handouts.

**Wave 2: with M4.8–M4.10 (lifecycle, finance, purchasing)**
- F21 beneficiary card, F09 purchase request, F08 price quote, F19 disbursement request, F20 equipment receipt pledge, F22 equipment return;
- the legal set F10 promissory note, F11–F15 deeds and F23 clearance, once G1 and G2 are decided;
- the contracts F16–F18, filled at print time.

**Wave 3: with M4.13 and M4.15**
- F05 special case;
- F28 questionnaire, F30 attendance and F31 minutes (print and Word);
- F32 and F33 training sheets.

**Wave 4: M4.18**
- printing a whole beneficiary file in procedure order (attachment numbers 1–31);
- exports.

## 9. Questions for the owner

1. **Idlib and Al Wadi.**
   - The papers have an Idlib folder (`013 IDL`) that prints the Aleppo address. The web has no Idlib city. Is Idlib a city to add, and what address should its papers print?
   - Al Wadi uses the Homs papers except for its own handout. Should Al Wadi's papers print the Homs address or a Wadi one?
2. **Centre representative (G1).** Who signs for the centre on the promissory note, the deeds, the clearance and the contracts in each city? Should their identity be stored once per city?
3. **Non-Syrian beneficiaries (G2).** Should the system store the identity kind and the ID issue (منح), so the right layout is chosen automatically?
4. **Contracts (G4).** Should the contract details be stored, or only filled at print time and kept as signed scans?
5. **Consents.** For the photo, audio and RC consents, is a signed scan enough, or should the choices be recorded?
6. **Caritas Polska consents.** Should the Caritas Polska consents print only for projects funded by Caritas Polska?
7. **Beneficiary card.** Are the 12 slots on the back of the beneficiary card the instalments? Should the due dates be pre-printed?
8. **Disbursement request.** Which layout is current? We assume the new one, with the 10% notes and the invoice table.
9. **The older questionnaire.** Is the per-person questionnaire (LAT, F29) retired in favour of the 8-page group questionnaire?
10. **Template errors.** Should the web version correct these, or reproduce the paper exactly?
    - The application form prints "توقيع مسجّل الطلب" twice. Is the third signature the interviewer (المحاور)?
    - In the minutes, axis 6 repeats axis 5's title.
    - The solar deeds cite clause /8/ where the recovery-ratio clause is /9/.
    - The older questionnaire asks for skills "للتمويل".
11. **Missing papers.** The procedure names several papers that are not in the folder: the visit guide, the needs scale, the receipt voucher, the opening and monitoring visit papers, the critical cases guide, the second committee's voting sheet and the budget study sheet. Do any of them exist on paper and need printing?
12. **Word output.** Besides the minutes and the questionnaire, does staff need any paper as an editable Word file?

## 10. Method and limits

- **How the files were read.**
  - The text layers were extracted with `pdftotext` and from the Word XML.
  - The PDFs and the Word background pictures were read visually from page images in a temporary folder outside the repository.
  - City copies were compared by file hash, by the hash of the extracted text, by the hash of each embedded picture and by pixel comparison of the PDF pages.
- **Not opened page by page.** Some copies were compared by hash only and are assumed to differ only in the footer:
  - the DMS/LAT pictures of several Word papers,
  - the HMS/DMS/LAT non-Syrian pictures,
  - the second LAT car-sale contract.
- **Sample data stays out of the repository.** The Word templates carry sample identities and amounts in their text boxes, and none of it was copied here.
