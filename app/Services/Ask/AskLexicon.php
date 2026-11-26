<?php

namespace App\Services\Ask;

/**
 * The vocabulary of the ask box: phrases (Arabic and English, already folded and stemmed) with a kind and a payload.
 * Kinds: stop, neg, with, gt, lt, ge, le, between, from, to, after, before, tunit (day|week|month|year), unit (age|usd|syp|amount|
 * loan|requested|project), mult, dual, last, next, rel, month, pay, pwith, datefield, fieldword, pnum, group, measure, entity.
 * Several entries may share a phrase; matchAt() takes the longest one of the kinds the caller asks for.
 */
final class AskLexicon
{
    /** @var array<string, list<array{tokens: list<string>, kind: string, payload: mixed}>> */
    private array $byFirst = [];

    public function add(string $phrase, string $kind, mixed $payload = null): void
    {
        $tokens = array_map(AskText::stem(...), AskText::tokenize($phrase));
        $tokens = array_values(array_filter($tokens, fn ($t) => $t !== ''));
        if ($tokens === []) {
            return;
        }
        $this->byFirst[$tokens[0]][] = ['tokens' => $tokens, 'kind' => $kind, 'payload' => $payload];
    }

    /** @param list<string> $phrases */
    public function addAll(array $phrases, string $kind, mixed $payload = null): void
    {
        foreach ($phrases as $phrase) {
            $this->add($phrase, $kind, $payload);
        }
    }

    /**
     * @param  list<array{alts: list<string>}>  $tokens
     * @param  list<string>  $kinds  in priority order
     * @return array{len: int, kind: string, payloads: list<mixed>}|null
     */
    public function matchAt(array $tokens, int $i, array $kinds): ?array
    {
        if (! isset($tokens[$i])) {
            return null;
        }
        $best = null;
        foreach ($tokens[$i]['alts'] as $alt) {
            foreach ($this->byFirst[$alt] ?? [] as $entry) {
                if (! in_array($entry['kind'], $kinds, true)) {
                    continue;
                }
                $len = count($entry['tokens']);
                $ok = true;
                for ($k = 1; $k < $len; $k++) {
                    if (! isset($tokens[$i + $k]) || ! in_array($entry['tokens'][$k], $tokens[$i + $k]['alts'], true)) {
                        $ok = false;
                        break;
                    }
                }
                if (! $ok) {
                    continue;
                }
                $rank = array_search($entry['kind'], $kinds, true);
                if ($best === null || $len > $best['len'] || ($len === $best['len'] && $rank < $best['rank'])) {
                    $best = ['len' => $len, 'kind' => $entry['kind'], 'rank' => $rank, 'payloads' => []];
                }
                if ($best['len'] === $len && $best['kind'] === $entry['kind']) {
                    $best['payloads'][] = $entry['payload'];
                }
            }
        }

        return $best === null ? null : ['len' => $best['len'], 'kind' => $best['kind'], 'payloads' => $best['payloads']];
    }

    /** Words that carry no filter of their own (and the question nouns), Arabic and English. */
    public static function fixed(): self
    {
        $l = new self;
        $l->addAll(['في', 'من', 'الى', 'على', 'عن', 'هل', 'ما', 'هي', 'هو', 'اعرض', 'اعرضلي', 'اظهر', 'اعطني', 'هات', 'اريد', 'ابحث', 'جد', 'لي', 'لنا', 'كل', 'جميع', 'ذات', 'الذين', 'التي', 'الذي', 'الي', 'هم', 'هن', 'يوجد', 'لدينا', 'عندنا', 'قائمه', 'قائمة',
            'show', 'list', 'display', 'find', 'get', 'give', 'me', 'us', 'all', 'every', 'the', 'a', 'an', 'of', 'in', 'on', 'at', 'for', 'to', 'who', 'whose', 'which', 'that', 'are', 'is', 'was', 'were', 'be', 'there', 'what', 'please', 'with', 'from', 'during', 'by', 'per', 'and', 'or', 'و', 'او', 'ثم',
            'مشروع', 'مشاريع', 'شخص', 'اشخاص', 'ناس', 'مستفيد', 'مستفيدين', 'مستفيدات', 'دفعه', 'دفعات', 'قسط', 'اقساط', 'تسديد', 'سجل', 'سجلات', 'صفوف', 'نتائج', 'بيانات', 'الدفعات',
            'project', 'projects', 'people', 'person', 'beneficiary', 'beneficiaries', 'payment', 'payments', 'installment', 'installments', 'record', 'records', 'rows', 'data', 'results', 'women', 'men'], 'stop');
        // "women" and "men" are also the sex words; the entity match wins when the dataset has a sex field (entities come first).
        $l->addAll(['و', 'او', 'and', 'or'], 'sep');
        $l->addAll(['بدون', 'بلا', 'ما عدا', 'ماعدا', 'عدا', 'باستثناء', 'استثناء', 'غير', 'ليس', 'ليست', 'لا', 'من غير', 'except', 'excluding', 'exclude', 'without', 'not', 'other than', 'no'], 'neg');
        $l->addAll(['مع', 'لديه', 'لديهم', 'لدى', 'لها', 'عندهم', 'عنده', 'with', 'having', 'has', 'have'], 'with');

        $l->addAll(['اكثر من', 'اكبر من', 'اعلى من', 'فوق', 'اكتر من', 'تزيد عن', 'يزيد عن', 'تتجاوز', 'يتجاوز', 'اكثر', 'more than', 'greater than', 'over', 'above', 'higher than', 'bigger than', 'exceeding', 'older than', '>'], 'gt');
        $l->addAll(['اقل من', 'اصغر من', 'تحت', 'دون', 'اقل', 'تقل عن', 'ينقص عن', 'less than', 'under', 'below', 'lower than', 'smaller than', 'younger than', '<'], 'lt');
        $l->addAll(['على الاقل', 'لا يقل عن', 'لا تقل عن', 'اكثر من او يساوي', 'at least', 'not less than', 'minimum', '>='], 'ge');
        $l->addAll(['كحد اقصى', 'لا يزيد عن', 'لا تزيد عن', 'حتى', 'up to', 'at most', 'not more than', 'maximum', 'until', 'اقل من او يساوي', '<='], 'le');
        $l->addAll(['بين', 'between'], 'between');
        $l->addAll(['من', 'from', 'منذ', 'since'], 'from');
        $l->addAll(['الى', 'إلى', 'to', 'حتى', 'until', 'لغايه', '-'], 'to');
        $l->addAll(['بعد', 'after'], 'after');
        $l->addAll(['قبل', 'before'], 'before');

        $l->addAll(['يوم', 'ايام', 'يوما', 'day', 'days'], 'tunit', 'day');
        $l->addAll(['اسبوع', 'اسابيع', 'week', 'weeks'], 'tunit', 'week');
        $l->addAll(['شهر', 'اشهر', 'شهور', 'month', 'months'], 'tunit', 'month');
        $l->addAll(['سنه', 'سنوات', 'سنين', 'عام', 'اعوام', 'year', 'years'], 'tunit', 'year');
        $l->addAll(['يومين'], 'dual', [2, 'day']);
        $l->addAll(['اسبوعين'], 'dual', [2, 'week']);
        $l->addAll(['شهرين'], 'dual', [2, 'month']);
        $l->addAll(['سنتين', 'عامين'], 'dual', [2, 'year']);
        $l->addAll(['مشروعين'], 'dual', [2, 'project']);

        $l->addAll(['سنه', 'سنوات', 'سنين', 'عام', 'اعوام', 'عمر', 'العمر', 'عمره', 'عمرها', 'عمرهم', 'age', 'aged', 'years old', 'year old', 'yrs', 'old'], 'unit', 'age');
        $l->addAll(['دولار', 'دولارا', 'usd', '$', 'dollar', 'dollars'], 'unit', 'usd');
        $l->addAll(['ليره', 'ل.س', 'lira', 'syp', 'sp', 'ليرات'], 'unit', 'syp');
        $l->addAll(['مبلغ', 'مبالغ', 'قيمه', 'amount', 'price', 'cost', 'تكلفه', 'value', 'مليغ'], 'unit', 'amount');
        $l->addAll(['مطلوب', 'المطلوب', 'requested', 'طلب مبلغ'], 'unit', 'requested');
        $l->addAll(['قرض', 'قروض', 'loan', 'loans'], 'unit', 'loan');
        $l->addAll(['مشروع', 'مشاريع', 'projects', 'project'], 'unit', 'project');
        $l->addAll(['الف', 'الاف', 'thousand', 'k'], 'mult', 1000);
        $l->addAll(['مليون', 'ملايين', 'million'], 'mult', 1000000);
        $l->addAll(['مليار', 'billion'], 'mult', 1000000000);

        $l->addAll(['اخر', 'اخيره', 'الاخيره', 'last', 'past', 'previous', 'في اخر', 'within', 'خلال'], 'last');
        $l->addAll(['القادمه', 'القادم', 'التاليه', 'next', 'upcoming', 'coming'], 'next');
        $rel = [
            'this_month' => ['هذا الشهر', 'هذه الشهر', 'الشهر الحالي', 'الشهر الجاري', 'this month', 'current month'],
            'last_month' => ['الشهر الماضي', 'الشهر الفائت', 'الشهر السابق', 'last month', 'previous month'],
            'next_month' => ['الشهر القادم', 'الشهر التالي', 'next month'],
            'this_year' => ['هذه السنه', 'هذا العام', 'السنه الحاليه', 'العام الحالي', 'السنه الجاريه', 'this year', 'current year'],
            'last_year' => ['السنه الماضيه', 'العام الماضي', 'السنه الفائته', 'العام الفائت', 'السنه السابقه', 'last year', 'previous year'],
            'next_year' => ['السنه القادمه', 'العام القادم', 'next year'],
            'this_week' => ['هذا الاسبوع', 'الاسبوع الحالي', 'this week'],
            'last_week' => ['الاسبوع الماضي', 'last week'],
            'today' => ['اليوم', 'today'],
            'yesterday' => ['امس', 'البارحه', 'yesterday'],
        ];
        foreach ($rel as $key => $phrases) {
            $l->addAll($phrases, 'rel', $key);
        }
        $months = [1 => ['كانون الثاني', 'يناير', 'january'], 2 => ['شباط', 'فبراير', 'february'], 3 => ['اذار', 'مارس', 'march'], 4 => ['نيسان', 'ابريل', 'april'],
            5 => ['ايار', 'مايو', 'may'], 6 => ['حزيران', 'يونيو', 'يونيه', 'june'], 7 => ['تموز', 'يوليو', 'يوليه', 'july'], 8 => ['اغسطس', 'august'],
            9 => ['ايلول', 'سبتمبر', 'september'], 10 => ['تشرين الاول', 'اكتوبر', 'october'], 11 => ['تشرين الثاني', 'نوفمبر', 'november'], 12 => ['كانون الاول', 'ديسمبر', 'december']];
        foreach ($months as $n => $phrases) {
            $l->addAll($phrases, 'month', $n);
        }
        $l->add('اب', 'month', -8); // "آب" folds to the word for father: only read as August beside "شهر" or a year

        $l->addAll(['متاخر', 'متاخرات', 'overdue', 'late', 'متاخره', 'متأخرة'], 'pay', 'overdue');
        $l->addAll(['غير مدفوع', 'غير مسدد', 'لم تدفع', 'لم يدفع', 'لم تسدد', 'غير مستلم', 'unpaid', 'not paid', 'outstanding', 'غير مسدده', 'غير مدفوعه'], 'pay', 'unpaid');
        $l->addAll(['مدفوع', 'مسدد', 'مستلم', 'paid', 'received', 'settled'], 'pay', 'paid');
        $l->addAll(['بدون مشاريع', 'بدون مشروع', 'من غير مشاريع', 'لا مشاريع', 'ليس لديهم مشاريع', 'لا يملكون مشاريع', 'without projects', 'no projects', 'with no projects'], 'pwith', 'without');
        $l->addAll(['لديهم مشاريع', 'مع مشاريع', 'عندهم مشاريع', 'لديه مشروع', 'with projects', 'having projects', 'who have projects', 'with a project'], 'pwith', 'with');

        $l->addAll(['الطلب', 'قدم', 'قدمت', 'تقدم', 'تقدموا', 'request', 'submitted', 'applied', 'requested on', 'تاريخ الطلب'], 'datefield', 'request_date');
        $l->addAll(['تغيرت الحاله', 'تغير الحاله', 'state changed', 'status changed', 'تاريخ تغير الحاله'], 'datefield', 'state_changed_on');
        $l->addAll(['سجلوا', 'تسجيل', 'مسجل', 'registered', 'joined', 'اضيفوا', 'اضيف', 'تاريخ التسجيل'], 'datefield', 'registered');
        $l->addAll(['مواليد', 'ولد', 'ولدوا', 'مولود', 'مولودين', 'born', 'birth', 'birthday', 'تاريخ الميلاد'], 'datefield', 'birth_date');
        $l->addAll(['استحقاق', 'مستحق', 'مستحقه', 'due', 'يستحق', 'تستحق', 'due on'], 'datefield', 'due_on');
        $l->addAll(['استلام', 'استلمت', 'received on', 'paid on', 'تاريخ الاستلام', 'تاريخ الدفع'], 'datefield', 'received_on');

        $fields = [
            'city' => ['مدينه', 'مدن', 'city', 'cities', 'محافظه'],
            'donor' => ['مانح', 'المانح', 'جهه مموله', 'جهه مانحه', 'جهه تمويل', 'donor', 'funder'],
            'group' => ['مجموعه مانح', 'مجموعه', 'donor group', 'group'],
            'project_type' => ['نوع المشروع', 'نوع', 'project type', 'type'],
            'fund_type' => ['نوع التمويل', 'fund type', 'funding type'],
            'sex' => ['جنس', 'الجنس', 'sex', 'gender'],
            'marital_status' => ['الحاله الاجتماعيه', 'حاله اجتماعيه', 'marital status', 'marital'],
            'education' => ['التعليم', 'المستوى التعليمي', 'تحصيل', 'education', 'تحصيل علمي'],
            'state' => ['حاله', 'حاله المشروع', 'state', 'status'],
            'stage' => ['مرحله', 'المرحله', 'stage'],
            'currency' => ['عمله', 'currency'],
            'status' => ['حاله الدفعه', 'payment status'],
        ];
        foreach ($fields as $key => $phrases) {
            $l->addAll($phrases, 'fieldword', $key);
        }
        $l->addAll(['مشروع', 'المشروع', 'مشاريع', 'رقم', 'ارقام', 'project', 'projects', 'no', 'number', 'numbers', '#', 'تحمل رقم'], 'pnum');

        $l->addAll(['حسب', 'بحسب', 'لكل', 'by', 'per', 'grouped by', 'for each', 'each', 'موزعه حسب', 'موزع حسب'], 'group');
        $l->addAll(['عدد', 'كم', 'count', 'how many', 'number of', 'تعداد'], 'measure', 'count');
        $l->addAll(['مجموع', 'اجمالي', 'sum', 'total', 'المجموع', 'الاجمالي'], 'measure', 'sum');
        $l->addAll(['متوسط', 'معدل', 'average', 'avg', 'mean'], 'measure', 'avg');

        $l->addAll(['اولي', 'مبدئي', 'preliminary', 'initial'], 'entity', ['stage', 'preliminary']);
        $l->addAll(['كامل', 'full'], 'entity', ['stage', 'full']);
        $l->addAll(['ذكر', 'ذكور', 'رجل', 'رجال', 'male', 'males', 'man', 'men', 'boy'], 'entity', ['sex', 'male']);
        $l->addAll(['انثي', 'اناث', 'امراه', 'نساء', 'سيدات', 'سيده', 'female', 'females', 'woman', 'women', 'girl'], 'entity', ['sex', 'female']);
        $l->addAll(['usd', 'dollar', 'دولار'], 'currencyword', 'USD');
        $l->addAll(['syp', 'ليره', 'ل.س', 'sp'], 'currencyword', 'SYP');
        // Synonyms of project states.
        $l->addAll(['funded', 'ممولين'], 'entity_en', 'Financed');
        $l->addAll(['approved', 'موافق عليه'], 'entity_en', 'Accepted');
        $l->addAll(['withdrawn', 'withdrew'], 'entity_en', 'Withdrew');
        $l->addAll(['cancelled'], 'entity_en', 'Canceled');
        $l->addAll(['students', 'طلاب', 'طلبه'], 'entity_en', 'Student');
        $l->addAll(['خريج', 'خريجين', 'graduates', 'خريجات'], 'entity_en', 'Graduate');
        $l->addAll(['اعزب', 'عزاب', 'عزباء', 'singles', 'unmarried'], 'entity_en', 'Single');
        $l->addAll(['ارمل', 'ارامل', 'ارمله'], 'entity_en', 'Widowed');
        $l->addAll(['مطلق', 'مطلقين', 'مطلقه', 'divorced', 'مطلقات'], 'entity_en', 'Divorced or Separated');

        return $l;
    }
}
