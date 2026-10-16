<?php

namespace Arzcode\FilamentDocs;

use Arzcode\FilamentDocs\Http\Controllers\DocumentationImageController;
use Arzcode\FilamentDocs\Pages\Documentation;
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

class FilamentDocsPlugin implements Plugin
{
    use EvaluatesClosures;

    public const string ID = 'filament-docs';

    /** Name of the screenshot route, relative to the panel's route names. */
    public const string IMAGE_ROUTE = 'docs.image';

    protected ?Closure $authorizeUsing = null;
    protected string $slug = 'docs';
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
