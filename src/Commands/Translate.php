<?php

namespace Alisalehi\LaravelLangFilesTranslator\Commands;

use Alisalehi\LaravelLangFilesTranslator\Services\TranslateService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;

class TranslateCommand extends Command
{
    protected $signature = 'translate:lang 
        {from : Source language code (e.g. "en")}
        {to : Target language code (e.g. "fr")}
        {--f|force : Overwrite existing translations}
        {--d|dry-run : Perform a trial run without actual translation}
        {--p|progress : Show progress bar during translation}
        {--c|chunk=100 : Number of items to process at once}';
    
    protected $description = 'Translate language files between locales';
    
    private TranslateService $translateService;
    
    public function __construct(TranslateService $translateService)
    {
        parent::__construct();
        $this->translateService = $translateService;
    }
    
    public function handle(): int
    {
        $this->showWelcomeMessage();
        
        try {
            $this->validateArguments();
            
            $this->translateService
                ->setOutput($this->output)
                ->setFrom($this->argument('from'))
                ->setTo($this->argument('to'))
                ->setForce($this->option('force'))
                ->setDryRun($this->option('dry-run'))
                ->setChunkSize((int)$this->option('chunk'));
            
            if ($this->option('progress')) {
                $this->translateService->setProgressCallback(
                    fn($total) => $this->createProgressBar($total)
                );
            }
            
            $result = $this->translateService->translate();
            
            $this->showCompletionMessage($result);
            $this->showThanksMessage();
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Translation failed: ' . $e->getMessage());
            $this->error('Exception trace: ' . $e->getTraceAsString());
            
            return Command::FAILURE;
        }
    }
    
    private function showWelcomeMessage(): void
    {
        $this->output->title('Laravel Language Files Translator');
        $this->line('Starting translation process...');
        $this->newLine();
        
        $this->line('Translation speed depends on:');
        $this->line('- Your internet connection speed');
        $this->line('- Number of translation keys');
        $this->line('- Depth of nested translation arrays');
        $this->newLine();
        
        $this->line('Please be patient while the translation completes.');
        $this->line('For large files, consider using --chunk option.');
        $this->newLine();
    }
    
    private function validateArguments(): void
    {
        if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $this->argument('from'))) {
            throw new \InvalidArgumentException('Invalid source language code format');
        }
        
        if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $this->argument('to'))) {
            throw new \InvalidArgumentException('Invalid target language code format');
        }
        
        if ($this->argument('from') === $this->argument('to')) {
            throw new \InvalidArgumentException('Source and target languages cannot be the same');
        }
    }
    
    private function createProgressBar(int $total): ProgressBar
    {
        $progressBar = $this->output->createProgressBar($total);
        $progressBar->setFormat(
            "%current%/%max% [%bar%] %percent:3s%%\n" .
            "Elapsed: %elapsed:6s% | Remaining: %remaining:6s%\n" .
            "Memory: %memory:6s%"
        );
        
        return $progressBar;
    }
    
    private function showCompletionMessage(array $result): void
    {
        $this->newLine(2);
        $this->output->success('Translation completed successfully!');
        
        $this->table(
            ['Metric', 'Value'],
            [
                ['Source Language', $this->argument('from')],
                ['Target Language', $this->argument('to')],
                ['Files Processed', $result['files_processed']],
                ['Keys Translated', $result['keys_translated']],
                ['Skipped Keys', $result['skipped_keys']],
                ['Execution Time', $result['execution_time'] . ' seconds'],
            ]
        );
        
        $this->line('Translated files are available in: lang/' . $this->argument('to'));
        
        if ($this->option('dry-run')) {
            $this->warn('DRY RUN: No files were actually modified');
        }
    }
    
    private function showThanksMessage(): void
    {
        $this->newLine();
        $this->output->block(
            ['Thank you for using Laravel Lang Files Translator!'],
            'success',
            'fg=black;bg=green',
            ' ',
            true
        );
        
        $this->output->block(
            [
                'If you find this package useful,',
                'please consider giving it a star on GitHub!',
                '',
                'GitHub: https://github.com/alisalehi1380/laravel-lang-files-translator',
                '',
                'Regards,',
                'Ali Salehi'
            ],
            null,
            'fg=yellow;bg=blue',
            ' ⭐ ',
            true
        );
    }
}
