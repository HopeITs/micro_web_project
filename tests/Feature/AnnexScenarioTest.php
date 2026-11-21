<?php

namespace Tests\Feature;

use App\Livewire\ProjectAnnex;
use App\Models\WebAccount;
use App\Services\Applications\ProjectAnnexService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** M4.6 user scenarios: data entry fills the agriculture and rehabilitation annexes; other roles only read them. */
class AnnexScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function projectOfType(string $marker, int $skip = 0): int
    {
        $type = (int) DB::table('project_types')->where('name_ar', 'like', '%'.$marker.'%')->value('id');
        $id = (int) DB::table('projects')->orderBy('id')->skip($skip)->value('id');
        DB::table('projects')->where('id', $id)->update(['project_type_id' => $type]);

        return $id;
    }

    private function as(string $username): void
    {
        $this->actingAs(WebAccount::where('username', $username)->firstOrFail());
    }

    /** One answer per question: the first (and two for a multiple-answer question). */
    private function agricultureAnswers(): array
    {
        $answers = [];
        foreach (app(ProjectAnnexService::class)->questions('agriculture') as $q) {
            $answers[$q->id] = $q->multiple ? $q->answers->take(2)->pluck('id')->map(fn ($id) => (string) $id)->all() : (string) $q->answers->first()->id;
        }

        return $answers;
    }

    public function test_agriculture_annex_needs_every_answer_and_replaces_on_save(): void
    {
        $this->as('role.data-entry');
        $project = $this->projectOfType('زراع');
        $this->get('/ar/projects/'.$project)->assertSee(route('applications.annex', ['locale' => 'ar', 'project' => $project]));
        $this->get('/ar/applications/'.$project.'/annex')->assertOk()->assertSee(__('annexes.agriculture', [], 'ar'))->assertSee('هل لديك أرض زراعية؟');

        $first = (int) DB::table('ag_questions')->orderBy('sort_order')->value('id');
        Livewire::test(ProjectAnnex::class, ['projectId' => $project])->call('save')->assertHasErrors('answers.'.$first);
        $this->assertSame(0, DB::table('project_agriculture_answers')->where('project_id', $project)->count());

        $answers = $this->agricultureAnswers();
        $component = Livewire::test(ProjectAnnex::class, ['projectId' => $project])->set('answers', $answers)->call('save')->assertHasNoErrors()->assertSee(__('annexes.saved', [], 'ar'));
        $expected = collect($answers)->sum(fn ($a) => count((array) $a));
        $this->assertSame($expected, DB::table('project_agriculture_answers')->where('project_id', $project)->whereNull('deleted_at')->count());

        // Saving again replaces the answers; a single-answer question refuses two answers.
        $component->set('answers.'.$first, (string) DB::table('ag_answers')->where('ag_question_id', $first)->orderByDesc('id')->value('id'))->call('save')->assertHasNoErrors();
        $this->assertSame($expected, DB::table('project_agriculture_answers')->where('project_id', $project)->whereNull('deleted_at')->count());
        $both = DB::table('ag_answers')->where('ag_question_id', $first)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $component->set('answers.'.$first, $both)->call('save')->assertHasErrors('answers.'.$first);

        $component->call('remove');
        $this->assertSame(0, DB::table('project_agriculture_answers')->where('project_id', $project)->whereNull('deleted_at')->count());
    }

    public function test_rehabilitation_annex_with_items_and_place_after_funding(): void
    {
        $this->as('role.data-entry');
        $project = $this->projectOfType('ترميم');
        $city = (int) DB::table('projects')->where('id', $project)->value('city_id');
        $component = Livewire::test(ProjectAnnex::class, ['projectId' => $project])->assertSet('kind', 'rehabilitation');
        $items = $component->get('items');
        foreach (app(ProjectAnnexService::class)->questions('rehabilitation') as $q) {
            $items[$q->id]['answers'] = $q->multiple ? [(string) $q->answers->first()->id] : (string) $q->answers->first()->id;
        }
        $block = (int) DB::table('rehab_questions')->where('name_ar', 'بناء بلوك')->value('id');
        $items[$block] = ['answers' => DB::table('rehab_answers')->where('rehab_question_id', $block)->limit(2)->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'note' => '3×4 م', 'quantity' => '2', 'price' => '150,000', 'result' => 'مطابق'];
        $street = (string) DB::table('streets')->where('city_id', $city)->value('id');
        $component->set('items', $items)->set('header.property_ownership', 'ملك')->set('header.property_area', '40 م²')
            ->set('header.street_id', $street)->set('header.address_after_fund', 'جانب الفرن')
            ->call('save')->assertHasNoErrors()->assertSee('300,000');
        // Reloaded from the database, 150000 stays 150000 (zeros are trimmed only after a decimal point).
        $component->assertSet("items.$block.price", '150000');

        $rehab = DB::table('project_rehabilitations')->where('project_id', $project)->first();
        $this->assertSame('ملك', $rehab->property_ownership);
        $this->assertSame('role.data-entry', $rehab->created_by_name);
        // Two answers of the block question → two rows carrying the same quantity and price.
        $this->assertSame(2, DB::table('project_rehabilitation_items')->where('project_rehabilitation_id', $rehab->id)->where('rehab_question_id', $block)->where('quantity', 2)->count());
        $this->assertDatabaseHas('projects', ['id' => $project, 'street_id' => $street, 'address_after_fund' => 'جانب الفرن']);

        // Reopened, the annex shows what was saved; the editor's name is kept.
        Livewire::test(ProjectAnnex::class, ['projectId' => $project])->assertSet('header.property_area', '40 م²')->assertSet("items.$block.quantity", '2')->call('save');
        $this->assertSame('role.data-entry', DB::table('project_rehabilitations')->where('id', $rehab->id)->value('edited_by_name'));
    }

    public function test_only_annex_types_and_writers(): void
    {
        $this->as('role.guest');
        $project = $this->projectOfType('زراع');
        $this->get('/ar/applications/'.$project.'/annex')->assertOk()->assertSee(__('annexes.read_only', [], 'ar'));
        Livewire::test(ProjectAnnex::class, ['projectId' => $project])->set('answers', $this->agricultureAnswers())->call('save')->assertForbidden();

        $this->as('role.data-entry');
        $plain = $this->projectOfType('مشاريع صغيرة', 1);
        $this->get('/ar/applications/'.$plain.'/annex')->assertNotFound();
        $this->get('/ar/projects/'.$plain)->assertDontSee(route('applications.annex', ['locale' => 'ar', 'project' => $plain]));
    }
}
