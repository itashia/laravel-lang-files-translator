<?php

namespace Alisalehi\LaravelLangFilesTranslator\Commands;

use Alisalehi\LaravelLangFilesTranslator\Services\TranslateService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;

class Translate extends Command
{
    protected $signature = 'translate:lang 
        {from : Source language code (e.g. en)}
        {to : Target language code (e.g. fa)}
        {--dry-run : Perform translation without saving files}
        {--no-preserve : Do not preserve parameters (e.g. :name)}';
    
    protected $description = 'Translate Laravel language files using Google Translate';
    
    public function handle(TranslateService $service)
    {
        $this->displayWelcomeMessage();
        
        try {
            $results = $service
                ->from($this->argument('from'))
                ->to($this->argument('to'))
                ->dryRun($this->option('dry-run'))
                ->preserveParameters(!$this->option('no-preserve'))
                ->translate();
            
            $this->displayResults($results);
            $this->displaySuccessMessage();
        } catch (\Exception $e) {
            $this->displayError($e);
        }
        
        $this->displayFooter();
    }
    
    private function displayWelcomeMessage(): void
    {
        $this->info(PHP_EOL . '🚀 Starting translation process');
        $this->line('----------------------------------------');
        $this->line('Source language: <comment>' . $this->argument('from') . '</comment>');
        $this->line('Target language: <comment>' . $this->argument('to') . '</comment>');
        $this->line('Dry run mode: <comment>' . ($this->option('dry-run') ? 'ON' : 'OFF') . '</comment>');
        $this->line('Preserve parameters: <comment>' . (!$this->option('no-preserve') ? 'YES' : 'NO') . '</comment>');
        $this->line('----------------------------------------');
        $this->newLine();
        
        $this->line('Please wait while we translate your language files...');
        $this->line('Translation speed depends on:');
        $this->line('- Your internet connection speed');
        $this->line('- Number of text entries');
        $this->line('- Translation API rate limits');
        $this->newLine();
    }
    
    private function displayResults(array $results): void
    {
        $this->table(
            ['File', 'Status'],
            array_map(
                fn($file, $success) => [
                    $file,
                    $success ? '<fg=green>✔ Success</>' : '<fg=yellow>⚠ Skipped</>'
                ],
                array_keys($results),
                array_values($results)
            )
        );
    }
    
    private function displaySuccessMessage(): void
    {
        if ($this->option('dry-run')) {
            $this->warn(PHP_EOL . 'DRY RUN COMPLETED - No files were actually saved');
        } else {
            $this->info(PHP_EOL . sprintf(
                'Successfully translated files to <comment>%s</comment> language!',
                $this->argument('to')
            ));
            $this->line(sprintf(
                'You can find the translated files in <comment>lang/%s/</comment> directory',
                $this->argument('to')
            ));
        }
    }
    
    private function displayError(\Exception $e): void
    {
        $this->newLine();
        $this->error('Translation failed!');
        $this->line('<fg=red>' . $e->getMessage() . '</>');
        $this->line('Please check your input and try again.');
    }
    
    private function displayFooter(): void
    {
        $this->newLine(2);
        $this->line('✨ <fg=cyan>Thank you for using Laravel Lang Files Translator!</> ✨');
        $this->line('If you find this package useful, please consider:');
        $this->line('- Giving it a ⭐ on GitHub');
        $this->line('- Reporting any issues you encounter');
        $this->line('- Contributing to the project');
        $this->newLine();
        $this->line('<fg=blue>GitHub:</> https://github.com/alisalehi1380/laravel-lang-files-translator');
        $this->line('<fg=blue>Author:</> Ali Salehi');
        $this->newLine();
    }
}
