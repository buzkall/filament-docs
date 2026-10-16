<x-filament-panels::page>
    <div class="flex flex-col gap-8 lg:flex-row" x-data="{ menuOpen: false }">
        <aside class="w-full shrink-0 lg:w-60">
            <x-filament::input.wrapper class="mb-3" :prefix-icon="\Filament\Support\Icons\Heroicon::OutlinedMagnifyingGlass">
                <x-filament::input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    :placeholder="__('filament-docs::docs.search_placeholder')"
                />
            </x-filament::input.wrapper>

            <button
                type="button"
                x-on:click="menuOpen = ! menuOpen"
                x-bind:aria-expanded="menuOpen"
                class="flex w-full items-center justify-between gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 lg:hidden dark:border-gray-700 dark:text-gray-300"
            >
                <span class="flex items-center gap-2">
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedBars3" class="h-5 w-5 shrink-0" />
                    {{ __('filament-docs::docs.menu') }}
                </span>
                <x-filament::icon
                    :icon="\Filament\Support\Icons\Heroicon::OutlinedChevronDown"
                    class="h-5 w-5 shrink-0 transition-transform"
                    x-bind:class="menuOpen && 'rotate-180'"
                />
            </button>

            <nav
                class="mt-3 space-y-4 lg:sticky lg:top-4 lg:mt-0 lg:block"
                x-bind:class="menuOpen ? 'block' : 'hidden'"
                x-on:click="menuOpen = false"
            >
                @foreach ($this->getSidebarGroups() as $group)
                    <div>
                        @if ($group['label'])
                            <h3 class="mb-2 text-xs font-semibold tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                {{ $group['label'] }}
                            </h3>
                        @endif
                        <ul class="space-y-1">
                            @foreach ($group['items'] as $item)
                                <li>
                                    <a
                                        href="{{ $item['url'] }}"
                                        @class([
                                            'flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition',
                                            'bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400' => $docSlug === $item['slug'],
                                            'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800' => $docSlug !== $item['slug'],
                                        ])
                                    >
                                        <x-filament::icon :icon="$item['icon']" class="h-5 w-5 shrink-0" />
                                        {{ $item['label'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </nav>
        </aside>

        <main class="min-w-0 flex-1">
            @if (filled($search))
                @php($results = $this->getSearchResults())

                <h2 class="text-base font-semibold text-gray-700 dark:text-gray-300">
                    {{ __('filament-docs::docs.results_for', ['term' => $search]) }} ({{ count($results) }})
                </h2>

                @if (filled($results))
                    <ul class="mt-4 space-y-2">
                        @foreach ($results as $result)
                            <li>
                                <a
                                    href="{{ $result['url'] }}"
                                    class="flex flex-col gap-1 rounded-lg border border-gray-200 px-4 py-3 transition hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800"
                                >
                                    <span class="flex items-center gap-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                                        <x-filament::icon :icon="$result['icon']" class="h-5 w-5 shrink-0" />
                                        {{ $result['label'] }}
                                    </span>
                                    {{-- The snippet is escaped in Documentation::buildSnippet(), around its <mark>. --}}
                                    <span class="text-sm text-gray-500 dark:text-gray-400">{!! $result['snippet'] !!}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('filament-docs::docs.no_results') }}</p>
                @endif
            @else
                @if ($screenUrl = $this->getScreenUrl())
                    <div class="mb-4 flex justify-end">
                        <x-filament::link :href="$screenUrl" :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowTopRightOnSquare" icon-position="after">
                            {{ __('filament-docs::docs.open_screen') }}
                        </x-filament::link>
                    </div>
                @endif

                @if ($toc = $this->getTableOfContents())
                    <details class="mb-6 rounded-lg border border-gray-200 px-4 py-3 xl:hidden dark:border-gray-700">
                        <summary class="cursor-pointer text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ __('filament-docs::docs.on_this_page') }} ({{ count($toc) }})
                        </summary>
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach ($toc as $heading)
                                <li @class(['ps-4' => $heading['level'] === 3])>
                                    <a href="#{{ $heading['id'] }}" class="text-gray-600 hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400">
                                        {{ $heading['text'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif

                <div class="fi-docs-content prose prose-gray dark:prose-invert max-w-none">{!! $this->getRenderedContent() !!}</div>

                @php($pager = $this->getPager())

                @if ($pager['previous'] || $pager['next'])
                    <nav class="mt-10 grid gap-3 border-t border-gray-200 pt-6 sm:grid-cols-2 dark:border-gray-700">
                        @foreach (['previous', 'next'] as $direction)
                            @if ($item = $pager[$direction])
                                <a
                                    href="{{ $item['url'] }}"
                                    @class([
                                        'flex flex-col gap-1 rounded-lg border border-gray-200 px-4 py-3 transition hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800',
                                        'sm:col-start-2 sm:text-end' => $direction === 'next',
                                    ])
                                >
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ __("filament-docs::docs.{$direction}") }}</span>
                                    <span @class(['flex items-center gap-2 text-sm font-medium text-gray-900 dark:text-gray-100', 'sm:justify-end' => $direction === 'next'])>
                                        <x-filament::icon :icon="$item['icon']" class="h-5 w-5 shrink-0" />
                                        {{ $item['label'] }}
                                    </span>
                                </a>
                            @endif
                        @endforeach
                    </nav>
                @endif
            @endif
        </main>

        @if (blank($search) && ($toc = $this->getTableOfContents()))
            {{-- Scroll-spy after Laradocs: the active section is the last heading that has
                 passed under the topbar, and headings too close to the end to ever get
                 there share out the remaining scroll so they still light up. --}}
            <aside
                class="hidden w-56 shrink-0 xl:block"
                x-data="{
                    ids: @js(array_column($toc, 'id')),
                    active: null,
                    line: 96,
                    ticking: false,
                    onScroll() {
                        if (this.ticking) return
                        this.ticking = true
                        requestAnimationFrame(() => { this.update(); this.ticking = false })
                    },
                    update() {
                        const headings = this.ids.map((id) => document.getElementById(id))
                        if (headings.some((heading) => ! heading)) return
                        const max = Math.max(0, document.documentElement.scrollHeight - window.innerHeight)
                        const triggers = headings.map((heading) => heading.getBoundingClientRect().top + window.scrollY - this.line)
                        const unreachable = triggers.findIndex((trigger) => trigger > max)
                        if (unreachable !== -1) {
                            const from = unreachable > 0 ? Math.min(triggers[unreachable - 1], max) : 0
                            const count = triggers.length - unreachable
                            for (let i = 0; i < count; i++) triggers[unreachable + i] = from + ((max - from) * (i + 1)) / (count + 1)
                        }
                        let active = this.ids[0]
                        triggers.forEach((trigger, i) => { if (window.scrollY >= trigger - 1) active = this.ids[i] })
                        this.active = active
                    },
                    init() {
                        this.listener = () => this.onScroll()
                        window.addEventListener('scroll', this.listener, { passive: true })
                        this.update()
                    },
                    destroy() {
                        window.removeEventListener('scroll', this.listener)
                    },
                }"
            >
                <nav class="sticky top-20">
                    <h3 class="text-xs font-semibold tracking-wider text-gray-500 uppercase dark:text-gray-400">
                        {{ __('filament-docs::docs.on_this_page') }}
                    </h3>
                    <ul class="mt-2 space-y-1 border-s border-gray-200 text-sm dark:border-gray-700">
                        @foreach ($toc as $heading)
                            <li>
                                <a
                                    href="#{{ $heading['id'] }}"
                                    @class(['-ms-px block border-s py-1 transition', 'ps-3' => $heading['level'] === 2, 'ps-6' => $heading['level'] === 3])
                                    x-bind:class="active === @js($heading['id'])
                                        ? 'border-primary-500 font-medium text-primary-600 dark:text-primary-400'
                                        : 'border-transparent text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200'"
                                >
                                    {{ $heading['text'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            </aside>
        @endif
    </div>
</x-filament-panels::page>
