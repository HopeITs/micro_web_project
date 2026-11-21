<?php

return [
    'title' => 'Checklist والتقييم', 'intro' => 'زيارات المستفيد، اختباره وتدريبه، آراء الفريق واللجنة، وتقييم المشروع. كل سؤال نعم/لا يفتح تفاصيله تحته.',
    'sections' => ['visits' => 'الزيارات', 'experience' => 'الخبرة والتدريب', 'scores' => 'تقييم المشروع', 'decision' => 'ملاحظات وقرار'],
    'lists' => ['key_people' => 'الأشخاص المفتاحيون', 'team' => 'رأي الفريق', 'committee' => 'رأي اللجنة'],
    'fields' => [
        'has_home_visit' => 'تمت زيارة المنزل', 'home_visit_date' => 'تاريخ زيارة المنزل', 'home_visit_time' => 'الوقت', 'home_visitor_1_id' => 'الزائر الأول', 'home_visitor_2_id' => 'الزائر الثاني', 'home_visit_note' => 'ملاحظات الزيارة',
        'has_project_visit' => 'تمت زيارة المحل', 'project_visit_date' => 'تاريخ زيارة المحل', 'project_visit_time' => 'الوقت', 'project_visitor_1_id' => 'الزائر الأول', 'project_visitor_2_id' => 'الزائر الثاني', 'project_visit_note' => 'ملاحظات الزيارة',
        'visits_report' => 'تقرير الزيارات',
        'needs_professional_test' => 'يحتاج اختباراً مهنياً', 'professional_test_need_note' => 'سبب الحاجة',
        'has_professional_test' => 'أُجري اختبار مهني', 'professional_test_date' => 'تاريخ الاختبار', 'professional_tester_id' => 'المختبِر', 'professional_test_note' => 'ملاحظات الاختبار', 'professional_test_result' => 'نتيجة الاختبار',
        'has_professional_training' => 'خضع لتدريب مهني', 'professional_trainer' => 'المدرّب', 'professional_training_place' => 'المكان', 'professional_training_start' => 'من', 'professional_training_end' => 'إلى', 'professional_training_note' => 'ملاحظات', 'professional_training_result' => 'النتيجة',
        'has_management_training' => 'خضع لتدريب إداري', 'management_trainer' => 'المدرّب', 'management_training_place' => 'المكان', 'management_training_date' => 'التاريخ', 'management_training_result' => 'النتيجة',
        'team_date' => 'تاريخ اجتماع الفريق', 'committee_date' => 'تاريخ اجتماع اللجنة', 'notes' => 'ملاحظات',
        'rejection_message' => 'أُرسلت رسالة الرفض', 'rejection_message_date' => 'تاريخ الرسالة', 'rejection_message_sender_account_id' => 'المرسِل',
    ],
    'member' => 'العضو', 'name_notes' => 'الاسم', 'role_notes' => 'الصفة', 'opinion' => 'الرأي', 'reason' => 'السبب', 'add_member' => 'إضافة عضو',
    'criterion' => 'المعيار', 'score' => 'التقييم',
    'save' => 'حفظ', 'print' => 'طباعة', 'delete' => 'حذف الـ Checklist', 'confirm_delete' => 'حذف الـ Checklist وآراء الأعضاء وتقييم المشروع؟',
    'saved' => 'حُفظ الـ Checklist.', 'deleted' => 'حُذف الـ Checklist.', 'read_only' => 'للعرض فقط: التعديل والطباعة للمسؤول وإدخال البيانات.',
    'print_labels' => [
        'info' => 'معلومات المستفيد', 'name' => 'الاسم', 'project_no' => 'رقم المشروع', 'city' => 'المدينة', 'project_name' => 'المشروع',
        'name_col' => 'الاسم', 'position' => 'العضو والمنصب', 'role' => 'الصفة', 'notes' => 'ملاحظات', 'visit' => 'الزيارات', 'visit_type' => 'الزيارة',
        'visit_date' => 'التاريخ', 'visit_time' => 'الوقت', 'visitors' => 'الزوار', 'home' => 'المنزل', 'project' => 'المحل', 'experience' => 'الخبرة والتدريب',
        'pro_test' => 'الاختبار المهني', 'test_date' => 'التاريخ', 'tester' => 'المختبِر', 'result' => 'النتيجة', 'pro_train' => 'التدريب المهني', 'trainer' => 'المدرّب',
        'place' => 'المكان', 'period' => 'المدة', 'mng_train' => 'التدريب الإداري', 'date' => 'التاريخ', 'meeting_date' => 'تاريخ الاجتماع', 'opinion' => 'الرأي',
        'reason' => 'السبب', 'signature' => 'التوقيع', 'printed' => 'طُبع في',
    ],
    'errors' => ['needed' => 'مطلوب عند الإجابة بنعم.', 'no_beneficiary' => 'لا يوجد مستفيد لهذا المشروع؛ أضفه في استمارة الطلب أولاً.'],
];
