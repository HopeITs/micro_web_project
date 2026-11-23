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
                @foreach(['On Hold' => 'تسجيل أولي', 'Waiting' => 'بالانتظار', 'Under Review' => 'قيد الدراسة', 'Delayed' => 'مؤجل', 'Rejected' => 'مرفوض', 'Withdrew' => 'منسحب', 'Accepted' => 'مقبول', 'Financed' => 'ممول', 'Canceled' => 'ملغى', 'Finished' => 'منتهي', 'Closed' => 'مغلق'] as $state => $name)
                    <x-hc.badge :tone="\App\Support\ProjectStatus::tone($state)" :icon="\App\Support\ProjectStatus::icon($state)">{{ $name }}</x-hc.badge>
                @endforeach
                <x-hc.badge tone="brand">كامل</x-hc.badge><x-hc.badge>أولي</x-hc.badge>
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
        <x-hc.card :title="__('hc.path_title')">
            <div class="hc-grid">
                @php($status = \App\Support\ProjectStatus::class)
                @php($p = $status::path('Under Review'))
                <x-hc.stage-bar :current="$p['current']" :mode="$p['mode']" :tone="$p['tone']" note="قيد الدراسة"/>
                <x-hc.next-step title="أكمل الـ Checklist وأرسله للقرار" waiting="موظف التقييم · حلب" since="منذ 9 أيام" :late="true" href="#" action="فتح الـ Checklist"/>
                @foreach([['Delayed', [], 'مؤجل'], ['Rejected', [], 'مرفوض'], ['Withdrew', ['On Hold', 'Under Review'], 'منسحب'], ['Accepted', [], 'مقبول'], ['Financed', [], 'ممول'], ['Canceled', ['Financed'], 'ملغى'], ['Finished', [], 'منتهي'], ['Closed', [], 'مغلق']] as [$state, $earlier, $name])
                    @php($p = $status::path($state, $earlier))
                    <x-hc.stage-bar :current="$p['current']" :mode="$p['mode']" :tone="$p['tone']" :note="$name"/>
                    @if($state === 'Financed')<x-hc.next-step title="زيارة الافتتاح خلال أسبوعين من التمويل" waiting="الفريق الميداني · دمشق" since="موعدها 27 نوفمبر" href="#" action="جدولة الزيارة"/>@endif
                @endforeach
            </div>
        </x-hc.card>
        <x-hc.card title="أرقام موجزة وفلاتر سريعة">
            <div class="hc-counts" style="margin-block-end:14px">
                <x-hc.count-tile :count="12" label="مسودات" icon="file"/>
                <x-hc.count-tile :count="20" label="بانتظار التقييم" tone="warn" icon="clock" :active="true"/>
                <x-hc.count-tile :count="7" label="أقساط متأخرة" tone="danger" icon="warning"/>
                <x-hc.count-tile :count="110" label="ممول" tone="ok" icon="check"/>
            </div>
            <div class="hc-chips" role="group" aria-label="{{ __('hc.quick_filters') }}">
                <x-hc.chip :count="200">الكل</x-hc.chip><x-hc.chip :count="50" :active="true">أولي</x-hc.chip><x-hc.chip :count="150">كامل</x-hc.chip><x-hc.chip :count="4">لي فقط</x-hc.chip>
            </div>
        </x-hc.card>
        <x-hc.card title="الرسوم البيانية" subtitle="x-hc.chart: type، labels، datasets، height؛ Chart.js يُحمَّل عند الحاجة، والألوان من رموز --chart-1..8، وجدول نصي بديل لقارئات الشاشة.">
            @php($chartLabels = ['دمشق', 'حلب', 'حمص', 'اللاذقية'])
            <div class="hc-grid hc-grid-2">
                <x-hc.chart type="bar" :labels="$chartLabels" :datasets="[['label' => 'مشاريع ممولة', 'data' => [42, 31, 18, 12]]]" title="مشاريع ممولة حسب المدينة" :height="260"/>
                <x-hc.chart type="stacked" :labels="$chartLabels" :datasets="[['label' => 'ممول', 'data' => [42, 31, 18, 12]], ['label' => 'قيد الدراسة', 'data' => [15, 22, 9, 6]], ['label' => 'مرفوض', 'data' => [4, 7, 3, 2]]]" title="الحالة حسب المدينة" :height="260"/>
                <x-hc.chart type="line" :labels="['2023', '2024', '2025', '2026']" :datasets="[['label' => 'طلبات', 'data' => [60, 88, 120, 140]], ['label' => 'تمويل', 'data' => [30, 52, 77, 103]]]" title="الطلبات والتمويل بالسنة" :height="260"/>
                <x-hc.chart type="doughnut" :labels="['ذكور', 'إناث']" :datasets="[['label' => 'المستفيدون', 'data' => [38, 62]]]" title="المستفيدون حسب الجنس" :height="260" :percent="true"/>
            </div>
        </x-hc.card>
        <x-hc.empty title="لا توجد نتائج" icon="search">جرّب كلمة أقصر أو امسح الفلاتر.<x-slot:action><x-hc.button variant="secondary" size="sm" icon="x">مسح الفلاتر</x-hc.button></x-slot:action></x-hc.empty>
        <x-hc.action-bar><x-hc.button icon="check">حفظ الطلب</x-hc.button><x-hc.button variant="secondary">حفظ كمسودة</x-hc.button><span class="spacer"></span><span class="muted">شريط الأزرار يبقى ظاهراً أسفل النماذج الطويلة</span></x-hc.action-bar>
    </div>
</x-layouts.hc>
