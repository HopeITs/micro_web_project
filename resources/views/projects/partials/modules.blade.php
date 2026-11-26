@if($page['modules'] !== [])
<nav class="hc-pj-modules" aria-label="{{ __('project_page.modules') }}">
    <ul>
        @foreach($page['modules'] as $module)
            <li><x-project.tile :module="$module"/></li>
        @endforeach
    </ul>
</nav>
@endif
