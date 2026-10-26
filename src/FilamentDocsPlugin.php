<?php

namespace Arzcode\FilamentDocs;

use Arzcode\FilamentDocs\Http\Controllers\DocumentationImageController;
use Arzcode\FilamentDocs\Pages\Documentation;
use Arzcode\FilamentDocs\Support\DocsConfig;
use BackedEnum;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Route;
use LogicException;
use UnitEnum;

/**
 * @phpstan-import-type Concept from DocsConfig
 */
class FilamentDocsPlugin implements Plugin
{
    use EvaluatesClosures;

    public const string ID = 'filament-docs';

    /** Name of the screenshot route, relative to the panel's route names. */
    public const string IMAGE_ROUTE = 'docs.image';

    protected ?Closure $authorizeUsing = null;
    protected ?Closure $preprocessMarkdownUsing = null;
    protected string $slug = 'docs';
    protected string|Closure|null $path = null;

    /** @var array<mixed>|Closure|null */
    protected array|Closure|null $concepts = null;

    protected bool|Closure $pdfExport = true;
    protected string|Closure|null $pdfLogo = null;
    protected string|UnitEnum|Closure|null $navigationGroup = null;
    protected ?int $navigationSort = 1000;
    protected string|BackedEnum|Htmlable|null $navigationIcon = Heroicon::OutlinedInformationCircle;

    public static function make(): static
    {
        /** @var static $plugin */
        $plugin = app(static::class);

        return $plugin;
    }

    /**
     * The plugin of the given or current panel, or null on a panel that did
     * not register it.
     */
    public static function get(?Panel $panel = null): ?static
    {
        $panel ??= Filament::getCurrentOrDefaultPanel();

        if (! $panel instanceof Panel || ! $panel->hasPlugin(static::ID)) {
            return null;
        }

        /** @var static $plugin */
        $plugin = $panel->getPlugin(static::ID);

        return $plugin;
    }

    public function getId(): string
    {
        return static::ID;
    }

    public function register(Panel $panel): void
    {
        if (preg_match('#^[a-z0-9-]+(/[a-z0-9-]+)*$#', $this->slug) !== 1) {
            throw new LogicException("The documentation slug [{$this->slug}] must be a lowercase URL path.");
        }

        $panel
            ->pages([Documentation::class])
            // The screenshots, behind the panel's own login: they show the
            // panel, so they are never a public asset.
            ->authenticatedRoutes(function(): void {
                Route::get("{$this->slug}/img/{path}", DocumentationImageController::class)
                    ->where('path', '.*')
                    ->name(self::IMAGE_ROUTE);
            });
    }

    public function boot(Panel $panel): void {}

    /**
     * Who may open the documentation. Receives the authenticated user.
     * Defaults to every user who can access the panel; the docs of screens
     * the user cannot open are filtered out one by one either way.
     */
    public function authorize(?Closure $callback): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    public function isAuthorized(?Authenticatable $user = null): bool
    {
        $user ??= Filament::auth()->user();

        if ($user === null) {
            return false;
        }

        if (! $this->authorizeUsing instanceof Closure) {
            return true;
        }

        return (bool)$this->evaluate($this->authorizeUsing, ['user' => $user]);
    }

    /**
     * The URL segment of the documentation inside the panel path. The
     * screenshots are served from `{slug}/img`.
     */
    public function slug(string $slug): static
    {
        $this->slug = trim($slug, '/');

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * The folder this panel's markdown lives in, when it is not the one in
     * `filament-docs.path`. The screenshots stay on the `filament-docs` disk
     * either way, so a slug has to be unique across panels.
     */
    public function path(string|Closure|null $path): static
    {
        $this->path = $path;

        return $this;
    }

    /** The folder of this panel's markdown, without a trailing slash. */
    public function getPath(): string
    {
        $path = $this->evaluate($this->path);

        return is_string($path) && $path !== '' ? rtrim($path, '/') : DocsConfig::path();
    }

    /** The markdown file of a doc in this panel's folder. */
    public function getDocPath(string $slug): string
    {
        return DocsConfig::docPath($slug, $this->getPath());
    }

    /**
     * This panel's concept docs, in place of `filament-docs.concepts`: slug =>
     * label, or slug => `['label' => …, 'icon' => …]`.
     *
     * @param  array<mixed>|Closure|null  $concepts
     */
    public function concepts(array|Closure|null $concepts): static
    {
        $this->concepts = $concepts;

        return $this;
    }

    /**
     * @return array<string, Concept>
     */
    public function getConcepts(): array
    {
        $concepts = $this->evaluate($this->concepts);

        return is_array($concepts) ? DocsConfig::normalizeConcepts($concepts) : DocsConfig::concepts();
    }

    /**
     * Rewrites a doc's markdown before it is rendered, searched or exported,
     * e.g. to expand the app's own tokens into HTML. Receives the markdown and
     * the doc's slug and returns the new markdown. Its output still goes
     * through the sanitizer.
     */
    public function preprocessMarkdown(?Closure $callback): static
    {
        $this->preprocessMarkdownUsing = $callback;

        return $this;
    }

    public function prepareMarkdown(string $markdown, string $slug): string
    {
        if (! $this->preprocessMarkdownUsing instanceof Closure) {
            return $markdown;
        }

        $prepared = $this->evaluate($this->preprocessMarkdownUsing, ['markdown' => $markdown, 'slug' => $slug]);

        if (! is_string($prepared)) {
            throw new LogicException('The preprocessMarkdown() callback must return the markdown as a string.');
        }

        return $prepared;
    }

    /** Root-relative URL prefix of the screenshots in the given panel. */
    public function getImagesUrl(Panel $panel): string
    {
        return '/' . implode('/', array_filter([trim($panel->getPath(), '/'), $this->slug, 'img']));
    }

    /**
     * Whether readers may export a doc, or the whole manual, as a PDF.
     * Receives the authenticated user when given a closure. On by default.
     */
    public function pdfExport(bool|Closure $condition = true): static
    {
        $this->pdfExport = $condition;

        return $this;
    }

    public function hasPdfExport(?Authenticatable $user = null): bool
    {
        $user ??= Filament::auth()->user();

        return (bool)$this->evaluate($this->pdfExport, ['user' => $user]);
    }

    /**
     * The absolute path of the image on the manual PDF's cover. Without one,
     * the cover shows only the title.
     */
    public function pdfLogo(string|Closure|null $path): static
    {
        $this->pdfLogo = $path;

        return $this;
    }

    public function getPdfLogo(): ?string
    {
        $path = $this->evaluate($this->pdfLogo);

        return is_string($path) && is_file($path) ? $path : null;
    }

    public function navigationGroup(string|UnitEnum|Closure|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): string|UnitEnum|null
    {
        $group = $this->evaluate($this->navigationGroup);

        return is_string($group) || $group instanceof UnitEnum ? $group : null;
    }

    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        return $this->navigationSort;
    }

    public function navigationIcon(string|BackedEnum|Htmlable|null $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    public function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return $this->navigationIcon;
    }
}
