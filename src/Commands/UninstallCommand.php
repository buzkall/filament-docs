<?php

namespace Arzcode\FilamentDocs\Commands;

use Arzcode\FilamentDocs\Support\DocsConfig;
use Arzcode\FilamentDocs\Support\PanelProviders;
use Arzcode\FilamentDocs\Support\Themes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\warning;

class UninstallCommand extends Command
{
    public $signature = 'filament-docs:uninstall';
    public $description = 'Reverse the Filament Docs install: unregister the plugin, take its styles out of the themes, and optionally delete the published config, translations, views and the docs themselves.';

    /** Whether a theme lost its docs lines, so the build has to run again. */
    protected bool $themesChanged = false;

    public function handle(): int
    {
        intro('Uninstalling Filament Docs');

        warning('This will remove Filament Docs from your application.');

        if (! confirm(label: 'Do you want to continue?', default: false)) {
            note('Aborted.');

            return self::SUCCESS;
        }

        $steps = [
            $this->unpatchPanelProviders(...),
            $this->unpatchThemes(...),
            $this->deleteConfigFile(...),
            $this->deletePublished(...),
            $this->deleteDocs(...),
            $this->runFinalSteps(...),
        ];

        foreach ($steps as $step) {
            $this->newLine();
            $step();
        }

        return self::SUCCESS;
    }

    protected function unpatchPanelProviders(): void
    {
        $files = PanelProviders::files();

        if ($files === []) {
            warning('No app/Providers/Filament/*PanelProvider.php found — nothing to clean.');

            return;
        }

        foreach ($files as $file) {
            $contents = (string)file_get_contents($file);
            $relative = $this->relativePath($file);

            if (! PanelProviders::hasPlugin($contents)) {
                note(sprintf('FilamentDocsPlugin not present in %s — skipping.', $relative));

                continue;
            }

            $patched = PanelProviders::removePlugin($contents);

            if (! PanelProviders::parses($patched)) {
                warning(sprintf('Could not unpatch %s safely — remove FilamentDocsPlugin from it manually.', $relative));

                continue;
            }

            if (PanelProviders::hasPlugin($patched)) {
                warning(sprintf('FilamentDocsPlugin still referenced in %s — remove it manually.', $relative));
            }

            file_put_contents($file, $patched);
            info(sprintf('Removed FilamentDocsPlugin from %s.', $relative));
        }
    }

    /**
     * Only the lines the install added. The typography plugin stays: the app
     * may use it for its own pages.
     */
    protected function unpatchThemes(): void
    {
        foreach (Themes::files() as $file) {
            $contents = (string)file_get_contents($file);
            $patched = Themes::unpatch($contents);

            if ($patched === $contents) {
                continue;
            }

            file_put_contents($file, $patched);
            $this->themesChanged = true;
            info(sprintf('Removed the docs styles from %s.', $this->relativePath($file)));
        }

        if (! $this->themesChanged) {
            note('No theme loads the docs styles — skipping.');
        }
    }

    protected function deleteConfigFile(): void
    {
        $file = config_path('filament-docs.php');

        if (! file_exists($file)) {
            note('No published config file found — skipping.');

            return;
        }

        if (! confirm(label: sprintf('Delete the published config file %s?', $this->relativePath($file)), default: false)) {
            note('Skipped — config file left in place.');

            return;
        }

        File::delete($file);
        info('Deleted ' . $this->relativePath($file));
    }

    protected function deletePublished(): void
    {
        foreach ([lang_path('vendor/filament-docs') => 'translations', resource_path('views/vendor/filament-docs') => 'views'] as $dir => $what) {
            if (! is_dir($dir)) {
                note("No published {$what} found — skipping.");

                continue;
            }

            if (! confirm(label: sprintf('Delete the published %s in %s?', $what, $this->relativePath($dir)), default: false)) {
                note("Skipped — {$what} left in place.");

                continue;
            }

            File::deleteDirectory($dir);
            info('Deleted ' . $this->relativePath($dir));
        }
    }

    /**
     * The markdown and the screenshots are the app's own writing, so they
     * stay unless the user explicitly says otherwise.
     */
    protected function deleteDocs(): void
    {
        $dir = DocsConfig::path();

        if (! is_dir($dir)) {
            note('No docs folder found — skipping.');

            return;
        }

        if (! confirm(
            label: sprintf('Delete the docs in %s?', $this->relativePath($dir)),
            default: false,
            hint: 'Every markdown file and screenshot in it is deleted.',
        )) {
            note('Skipped — the docs are left in place.');

            return;
        }

        File::deleteDirectory($dir);
        info('Deleted ' . $this->relativePath($dir));
    }

    protected function runFinalSteps(): void
    {
        $commands = ['composer remove arzcode/filament-docs' => 'Removing the arzcode/filament-docs package…'];

        if ($this->themesChanged) {
            $commands['npm run build'] = 'Rebuilding your Filament theme…';
        }

        foreach ($commands as $command => $message) {
            info($message);

            $result = Process::path(base_path())
                ->forever()
                ->run($command, function(string $type, string $output): void {
                    $this->output->write($output);
                });

            if (! $result->successful()) {
                error(sprintf('`%s` failed — run it manually to finish the uninstall.', $command));

                return;
            }
        }

        outro('Filament Docs uninstall complete.');
    }

    protected function relativePath(string $absolutePath): string
    {
        $base = base_path() . DIRECTORY_SEPARATOR;

        return str_starts_with($absolutePath, $base) ? substr($absolutePath, strlen($base)) : $absolutePath;
    }
}
