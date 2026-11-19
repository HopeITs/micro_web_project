<x-layouts.hc :title="__('hc.design_title')">
    <div class="hc-page-head"><div><p class="hc-eyebrow">M2.4 – M2.5</p><h1>{{ __('hc.design_title') }}</h1><p>{{ __('hc.design_intro') }}</p></div></div>
    <div class="hc-grid">
        <x-hc.card title="الأزرار والشارات">
            <div style="display:flex;flex-wrap:wrap;gap:10px;margin-block-end:14px">
                <x-hc.button icon="check">حفظ الطلب</x-hc.button>
                <x-hc.button variant="secondary" icon="file">حفظ كمسودة</x-hc.button>
                <x-hc.button variant="ghost">إلغاء</x-hc.button>
                <x-hc.button variant="danger" icon="x">حذف</x-hc.button>
                <x-hc.button :disabled="true">غير متاح</x-hc.button>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:8px">
                <x-hc.badge tone="ok">ممول</x-hc.badge><x-hc.badge tone="warn">قيد الدراسة</x-hc.badge><x-hc.badge tone="danger">مرفوض</x-hc.badge>
                <x-hc.badge tone="info">منتهي</x-hc.badge><x-hc.badge tone="brand">كامل</x-hc.badge><x-hc.badge>أولي</x-hc.badge>
                <x-hc.project-number code="DAM" :number="2354"/>
            </div>
        </x-hc.card>
        <x-hc.card title="التنبيهات">
            <div class="hc-grid">
                <x-hc.alert type="info" title="معلومة">رقم المشروع يُقترح تلقائياً ويمكن تعديله.</x-hc.alert>
                <x-hc.alert type="success" title="تم الحفظ">حُفظ الطلب DAM-1041 بنجاح.</x-hc.alert>
                <x-hc.alert type="warning" title="تنبيه">يوجد شخص آخر بنفس الرقم الوطني في حمص.</x-hc.alert>
                <x-hc.alert type="danger" title="خطأ">هذا الرقم مستخدم لمشروع آخر في نفس المدينة. أول رقم متاح: 1041.</x-hc.alert>
            </div>
        </x-hc.card>
        <x-hc.section title="حقول النموذج (مثال من ترتيب الورقة)" step="1">
            <div class="hc-form-grid">
                <x-hc.field label="رقم المشروع" for="d-num" hint="الرقم التالي في دمشق: 1041" :required="true"><input id="d-num" class="hc-input num ltr" value="1041" inputmode="numeric"></x-hc.field>
                <x-hc.field label="اسم المستفيد" for="d-name" :required="true"><input id="d-name" class="hc-input" placeholder="الاسم الأول"></x-hc.field>
                <x-hc.field label="سنة الميلاد" for="d-year" hint="السنة فقط كما في الورقة"><input id="d-year" class="hc-input num ltr" inputmode="numeric" maxlength="4" placeholder="1985"></x-hc.field>
                <x-hc.field label="المهنة" for="d-career"><select id="d-career" class="hc-select"><option>تكسي</option><option>نجارة</option></select></x-hc.field>
                <x-hc.field label="هل لديه عمل حالياً؟" for="d-work"><x-hc.yes-no name="d-work" value="1"/></x-hc.field>
                <x-hc.field label="المبلغ المطلوب" for="d-amount" error="أدخل مبلغاً أكبر من صفر."><input id="d-amount" class="hc-input num ltr" aria-invalid="true" value="0"></x-hc.field>
            </div>
            <div style="margin-block-start:16px"><x-hc.field label="ملاحظات" for="d-notes"><textarea id="d-notes" class="hc-textarea"></textarea></x-hc.field></div>
        </x-hc.section>
        <x-hc.empty title="لا توجد نتائج" icon="search">مثال على حالة فارغة في الجداول.</x-hc.empty>
        <x-hc.action-bar><x-hc.button icon="check">حفظ الطلب</x-hc.button><x-hc.button variant="secondary">حفظ كمسودة</x-hc.button><span class="spacer"></span><span class="muted">شريط الأزرار يبقى ظاهراً أسفل النماذج الطويلة</span></x-hc.action-bar>
    </div>
</x-layouts.hc>
