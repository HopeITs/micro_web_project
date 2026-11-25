<?php

// M4.20: the three photo consents, word for word from the owner's PDFs, spelling included: the two Caritas Polska consents (F24 adult, F25
// child; Polish beside Arabic) and Hope Center's informed consent (F27).
$means = 'تعني الموافقة أنه يمكن استخدام الصور أو مقاطع الفيديو أو التسجيلات في المنشورات أو وضعها على مواقع الانترنت وملفات وسائل التواصل الاجتماعي، وكذلك في المواد الأخرى التي يتم نشرها عن طريق الرؤية أو السمع أو الطباعة بواسطة:';
$withdraw = 'يحق لك سحب هذه الموافقة دون التأثير على قانونية معالجة البيانات قبل سحبها.';
$law = 'تشكل هذه الموافقة إذنًا لنشر الصورة بالمعنى المقصود في المادة. 81 من قانون 4 فبراير 1994 بشأن حق المؤلف والحقوق المجاورة.';
$plMeans = 'Wyrażenie zgody oznacza w szczególności, że fotografie, filmy lub nagrania mogą zostać wykorzystane w wydawanych publikacjach, umieszczone na stronach internetowych, profilach w mediach społecznościowych, a także w innych materiałach rozpowszechnianych za pomocą wizji, fonii lub druku przez Caritas Polska jako administratora danych osobowych oraz podmioty działające na zlecenie Caritas Polska w ww. celu.';
$plWithdraw = 'Ma Pani/Pan prawo wycofać niniejszą zgodę bez wpływu na zgodność z prawem przetwarzania danych w tym wizerunku przed jej wycofaniem.';
$plLaw = 'Niniejsza zgoda stanowi zezwolenie na rozpowszechnianie wizerunku w rozumieniu art. 81 ustawy z dnia 4 lutego 1994 r. o prawie autorskim i prawach pokrewnych.';
$plUses = [
    'w zakresie utrwalania i zwielokrotniania utworu - wytwarzanie określoną techniką egzemplarzy utworu, w tym techniką drukarską, reprograficzną, zapisu magnetycznego oraz techniką cyfrową;',
    'Korzystanie z uzyskanych materiałów i obrazów w celu popularyzacji projektu i dzielenie się nimi z osobami zainteresowanymi projektem;',
    'rozpowszechnianie tych informacji i danych na różne sposoby (dźwiękowe, wizualne lub pisemne), aby były powszechnie dostępne;',
];
$record = ['تسجيل العمل ونسخه', ' - إنتاج نسخ من العمل باستخدام تقنية معينة، بما في ذلك الطباعة، والنسخ، والتسجيل المغناطيسي، الالكتروني'];

return [
    'address' => 'ul. Okopowa 55, 01-043 Warszawa',
    'adult' => [
        'title' => 'الموافقة على استخدام الصورة الشخصية والصوت',
        'i' => 'أنا الموقع أدناه', 'agree' => 'أوافق على أنه يحق لمؤسسة كاريتاس بولندا', 'address_label' => 'عنوان:',
        'use' => 'الاستخدام المجاني لصورتي غير المحدود للوقت والإقليم عن طريق الصور والفيديو.', 'cases' => 'أوافق على التصوير والإنتاج والنشر في حالات التالية:',
        'uses' => [
            $record,
            ['استخدام الفيديوهات والصور', ' التي تم الحصول عليها من أجل جمع التبرعات ومشاركتها مع المهتمين بالمشروع'],
            ['نشر هذه المعلومات والبيانات', ' بطرق مختلفة (سمعية أو مرئية أو مكتوبة) لإتاحتها على نطاق واسع؛'],
        ],
        'after' => [$means, 'كاريتاس بولندا بصفته المسؤول عن البيانات الشخصية', 'أو الكيانات التي تعمل بالنيابة عن كاريتاس بولندا للغرض المذكور أعلاه.', $withdraw, $law],
        'name' => 'الاسم واسم العائلة:', 'date' => 'التاريخ', 'signature' => 'التوقيع',
        'pl' => [
            'title' => 'Zgoda na rozpowszechnianie wizerunku i głosu', 'i' => 'Ja,',
            'agree' => 'niżej podpisany wyrażam zgodę wobec Caritas Polska ul. Okopowa 55, 01-043 Warszawa, na bezpłatne i nieograniczone w czasie i terytorialnie rozpowszechnianie mojego wizerunku w postaci zdjęć i nagrań video poprzez jego utrwalenie, przetwarzanie i publikację na następujących polach eksploatacji:',
            'uses' => $plUses, 'after' => [$plMeans, $plWithdraw, $plLaw],
        ],
    ],
    'child' => [
        'title' => 'الموافقة على استخدام الصورة الشخصية والصوت والفيديو', 'controller' => 'مسؤول عن البيانات الشخصية',
        'i' => 'أنا ،اسمي', 'as' => '، بصفتي الممثل القانوني لـ', 'agree' => 'أوافق على أن لمؤسسة كاريتاس بولندا', 'address_label' => 'عنوانها:',
        'use' => 'الحق في الاستخدام المجاني لصورة وتسجيل الصوت والفيديو', 'for' => 'لـ',
        'cases' => 'غير المحدود للوقت والإقليم عن طريق الصور والفيديو. أوافق على التصوير والإنتاج والنشر في الحالات التالية:',
        'uses' => [
            $record,
            ['استخدام الفيديوهات والصور', ' التي تم الحصول عليها ونشرها ومشاركتها مع المهتمين بالمشروع'],
            ['نشر هذه المعلومات والبيانات', ' بطرق مختلفة (سمعية أو مرئية أو مكتوبة) لإتاحتها على نطاق واسع.'],
        ],
        'after' => [
            'تعني الموافقة على أنه يمكن استخدام الصور أو مقاطع الفيديو أو التسجيلات في المنشورات التي تم نشرها على مواقع الانترنت وملفات وسائل التواصل الاجتماعي، وكذلك في المواد الأخرى التي يتم نشرها عن طريق الرؤية أو السمع أو الطباعة بواسطة كاريتاس بولندا بصفته المسؤول عن البيانات الشخصية والكيانات التي تعمل بالنيابة عن كاريتاس بولندا للغرض المذكور أعلاه.',
            $withdraw, $law,
        ],
        'name' => 'الاسم واسم العائلة:', 'date' => 'التاريخ:', 'signature' => 'التوقيع:',
        'pl' => [
            'title' => 'ZGODA NA ROZPOWSZECHNIANIE WIZERUNKU I GŁOSU', 'controller' => 'Administrator',
            'as' => 'działając jako', 'i' => 'Ja,', 'representative' => 'przedstawiciel ustawowy',
            'agree' => 'wyrażam zgodę wobec Caritas Polska ul. Okopowa 55, 01-043 Warszawa, na bezpłatne i nieograniczone w czasie i terytorialnie rozpowszechnianie wizerunku i głosu',
            'form' => 'w postaci zdjęć i nagrań', 'rest' => 'video poprzez jego utrwalenie, przetwarzanie i publikację na następujących polach eksploatacji:',
            'uses' => $plUses, 'after' => [$plMeans, $plWithdraw, $plLaw],
        ],
    ],
    'informed' => [
        'title' => 'إقرار بالموافقة عن علم لاستخدام الصور والمواد المسجلة والمرئية',
        'read' => 'بعد قراءتي للوثيقة التوضيحية', 'policy' => 'لسياسة استخدام الصور والمواد المرئية', 'agree' => 'أوافق بموجبها على أن الصور / مقاطع الفيديو أو المعلومات الشخصية',
        'self' => 'الخاصة بشخصي', 'children' => 'طفلي/ أطفالي', 'guardian' => 'بصفتي الولي/الوصي القانوني عليهم', 'event' => '(اسم المشروع أو الحدث)', 'signing' => 'بالتوقيع على هذا الإقرار:',
        'understand' => [
            'أفهم سبب رغبتكم في تسجيل فيديو / التقاط صور فوتوغرافية لي أو لأطفالي.',
            'أفهم أن موافقتي، أو رفضي، لن يؤثر بأي شكل على المساعدات / خدمات الإغاثة التي أتلقاها من مؤسسة مركز الرجاء المسيحي.',
            'أفهم أن نشر هذه المواد يأتي كجزء من عمل العلاقات العامة لمؤسسة مركز الرجاء المسيحي.',
        ],
        'choices' => [
            'على إجراء مقابلة معي أو مع أطفالي ونشر المعلومات والبيانات بطرق مختلفة (مسموعة أو مكتوبة)',
            'على تصويري مقطع فيديو و/أو التقاط صور فوتوغرافية لي أو لأطفالي وفي أي وقت يُطلب مني ذلك في المستقبل والتنازل عن أي حقوق أو مطالبات مالية مقابل ذلك.',
            'على إمكانية استخدام هذه المادة في الأغراض الإعلامية المتعلقة بالقضايا الإنسانية على المستوى العالمي، وبهدف تعزيز الأمل، دعم الأسر السوريّة، جمع التبرعات، وتوثيق الأحداث في النشرات والكتيبات.',
            'على استخدام المواد على مواقع ومنصّات التواصل الاجتماعي التابعة لمؤسسة مركز الرجاء المسيحي، والجهات المانحة، وشركاء المشروع.',
        ],
        'hide' => 'في إخفاء هويتي (مثل إخفاء معالم الوجه أو استخدام اسم مستعار) عند نشر هذه المواد لضمان سلامتي.',
        'yes' => 'أوافق', 'no' => 'لا أوافق', 'want' => 'أرغب', 'not_want' => 'لا أرغب',
        'rights' => 'يحق لي الاستفسار في أي وقت عن نوع معالجة البيانات، أو طلب تصحيحها، أو تقييد استخدامها، أو حذفها.',
        'withdraw' => 'يمكنني سحب موافقتي في أي وقت عبر إرسال طلب إلى البريد الإلكتروني', 'email' => 'complaint@hcsyria.org',
        'withdraw_end' => 'وأفهم أن سحب الموافقة يوقف الاستخدام المستقبلي الرقمي، ولا يؤثر على قانونية الاستخدام السابق أو المواد المطبوعة التي تم توزيعها بالفعل قبل طلب السحب.',
        'name' => 'الاسم', 'signature' => 'التوقيع', 'date_place' => 'التاريخ والمكان',
    ],

    // The buttons of the project page.
    'print_photo_adult' => 'موافقة صورة (بالغ)', 'print_photo_child' => 'موافقة صورة (طفل)', 'print_photo_informed' => 'إقرار استخدام الصور',
];
