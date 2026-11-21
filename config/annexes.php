<?php

/*
 * M4.6 project annexes (desktop Agriculture_Form / ApplicationRehab_Form). An annex belongs to projects whose type name contains the
 * marker, as the desktop's AddExtention_button did. Owner decision (20 Nov 2026): a question takes one answer unless it is listed
 * under `multiple` (by its Arabic wording, which the desktop fixed); the desktop allowed several ticks everywhere.
 */
return [
    'agriculture' => [
        'type_marker' => 'زراع',
        'multiple' => [
            'المحاصيل التي لديك خبرة بزراعتها:', 'المصدر المائي:', 'طرق الري المستخدمة:', 'أنواع الطاقات المستخدمة:',
            'المعدات والأدوات والآليات الزراعية المتوفرة:',
            'أنواع الدعم الزراعي اللازم للاستمرار بالعمل الزراعي والحصول على مردود جيد يلبي احتياجات الأسرة:',
        ],
    ],
    'rehabilitation' => [
        'type_marker' => 'ترميم',
        'multiple' => ['بناء بلوك', 'الواجهة', 'تمديدات كهربائية'],
        // rehab_questions.type → the desktop section headers
        'groups' => [1 => 'civil', 2 => 'electrical', 3 => 'plumbing', 4 => 'other'],
    ],
];
