<?php

namespace Alisalehi\LaravelLangFilesTranslator\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Stichoza\GoogleTranslate\GoogleTranslate;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Console\Output\OutputInterface;
use Exception;

class TranslateService
{
    private string $sourceLanguage;
    private string $targetLanguage;
    private ?OutputInterface $output = null;
    private bool $forceOverwrite = false;
    private bool $dryRun = false;
    private int $chunkSize = 100;
    private $progressCallback = null;
    private array $translationStats = [
        'files_processed' => 0,
        'keys_translated' => 0,
        'skipped_keys' => 0,
        'failed_keys' => 0,
    ];
    private array $config = [
        'preserve_parameters' => true,
        'parameter_pattern' => '/:(\w+)/',
        'placeholder_wrapper' => '{}',
        'max_retries' => 3,
        'retry_delay' => 1000, // milliseconds
    ];

    // Fluent setters with type hints
    public function setFrom(string $language): self
    {
        $this->validateLanguageCode($language);
        $this->sourceLanguage = $language;
        return $this;
    }

    public function setTo(string $language): self
    {
        $this->validateLanguageCode($language);
        $this->targetLanguage = $language;
        return $this;
    }

    public function setOutput(OutputInterface $output): self
    {
        $this->output = $output;
        return $this;
    }

    public function setForce(bool $force): self
    {
        $this->forceOverwrite = $force;
        return $this;
    }

    public function setDryRun(bool $dryRun): self
    {
        $this->dryRun = $dryRun;
        return $this;
    }

    public function setChunkSize(int $size): self
    {
        $this->chunkSize = max(10, $size); // Ensure minimum chunk size
        return $this;
    }

    public function setProgressCallback(callable $callback): self
    {
        $this->progressCallback = $callback;
        return $this;
    }

    public function setConfig(array $config): self
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    public function translate(): array
    {
        $startTime = microtime(true);
        
        $this->validateSetup();
        $files = $this->getSourceLanguageFiles();

        foreach ($files as $file) {
            $this->processFile($file);
        }

        $this->translationStats['execution_time'] = round(microtime(true) - $startTime, 2);
        
        return $this->translationStats;
    }

    private function processFile(SplFileInfo $file): void
    {
        try {
            $this->log("Processing file: {$file->getFilename()}");
            
            $content = $this->loadFileContent($file);
            $translatedContent = $this->translateContent($content);
            
            if (!$this->dryRun) {
                $this->saveTranslation($file, $translatedContent);
            }
            
            $this->translationStats['files_processed']++;
        } catch (Exception $e) {
            $this->logError("Failed to process file {$file->getFilename()}: {$e->getMessage()}");
            throw $e;
        }
    }

    private function translateContent(array $content): array
    {
        if (empty($content)) {
            return [];
        }

        $googleTranslate = $this->createGoogleTranslateClient();
        $translated = [];
        $chunks = array_chunk($content, $this->chunkSize, true);

        foreach ($chunks as $chunk) {
            $translatedChunk = $this->translateChunk($chunk, $googleTranslate);
            $translated = array_merge($translated, $translatedChunk);
            
            if ($this->progressCallback) {
                call_user_func($this->progressCallback, count($chunk));
            }
        }

        return $translated;
    }

    private function translateChunk(array $chunk, GoogleTranslate $translator): array
    {
        $translatedChunk = [];
        
        foreach ($chunk as $key => $value) {
            if (is_array($value)) {
                $translatedChunk[$key] = $this->translateContent($value);
                continue;
            }

            try {
                $processedValue = $this->preProcessValue($value);
                $translatedValue = $this->retryTranslation(
                    fn() => $translator->translate($processedValue),
                    $this->config['max_retries'],
                    $this->config['retry_delay']
                );
                $translatedChunk[$key] = $this->postProcessValue($translatedValue, $value);
                
                $this->translationStats['keys_translated']++;
            } catch (Exception $e) {
                $this->logError("Failed to translate key '$key': {$e->getMessage()}");
                $translatedChunk[$key] = $value; // Keep original value
                $this->translationStats['failed_keys']++;
            }
        }

        return $translatedChunk;
    }

    private function preProcessValue(string $value): string
    {
        if (!$this->config['preserve_parameters']) {
            return $value;
        }

        // Replace :param with {param} to protect during translation
        return preg_replace_callback(
            $this->config['parameter_pattern'],
            fn($matches) => $this->wrapPlaceholder($matches[1]),
            $value
        );
    }

    private function postProcessValue(string $translated, string $original): string
    {
        if (!$this->config['preserve_parameters']) {
            return $translated;
        }

        // Restore original parameters
        $params = [];
        preg_match_all($this->config['parameter_pattern'], $original, $params);
        
        foreach ($params[1] ?? [] as $param) {
            $placeholder = $this->wrapPlaceholder($param);
            $translated = str_replace($placeholder, ":$param", $translated);
        }

        return $translated;
    }

    private function wrapPlaceholder(string $param): string
    {
        [$open, $close] = str_split($this->config['placeholder_wrapper']);
        return $open . $param . $close;
    }

    private function retryTranslation(callable $callback, int $maxRetries, int $delayMs)
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < $maxRetries) {
            try {
                return $callback();
            } catch (Exception $e) {
                $lastException = $e;
                $attempts++;
                $this->log("Retry attempt $attempts/$maxRetries after {$delayMs}ms");
                usleep($delayMs * 1000);
            }
        }

        throw $lastException ?? new Exception('Translation failed');
    }

    private function saveTranslation(SplFileInfo $sourceFile, array $content): void
    {
        $targetPath = $this->getTargetFilePath($sourceFile);
        
        if (File::exists($targetPath) && !$this->forceOverwrite) {
            $this->log("Skipping existing file: {$targetPath} (use --force to overwrite)");
            return;
        }

        $this->ensureDirectoryExists(dirname($targetPath));
        $phpContent = $this->generatePhpFileContent($content);
        
        File::put($targetPath, $phpContent);
        $this->log("Saved translation to: {$targetPath}");
    }

    private function generatePhpFileContent(array $data): string
    {
        $export = var_export($data, true);
        
        // Clean up array formatting
        $export = preg_replace('/array \(/', '[', $export);
        $export = preg_replace('/\)$/', ']', $export);
        $export = preg_replace('/\n\s*/', ' ', $export);
        
        return "<?php\n\nreturn " . $export . ';';
    }

    private function loadFileContent(SplFileInfo $file): array
    {
        try {
            return include $file->getPathname();
        } catch (Exception $e) {
            throw new Exception("Failed to load file {$file->getFilename()}: {$e->getMessage()}");
        }
    }

    private function getSourceLanguageFiles(): array
    {
        $sourcePath = $this->getSourceLanguagePath();
        
        $this->validateSourceDirectory($sourcePath);
        
        $files = File::files($sourcePath);
        
        if (empty($files)) {
            throw new Exception("No language files found in {$sourcePath}");
        }
        
        return $files;
    }

    private function getTargetFilePath(SplFileInfo $sourceFile): string
    {
        $fileName = $sourceFile->getFilename();
        return lang_path($this->targetLanguage . DIRECTORY_SEPARATOR . $fileName);
    }

    private function getSourceLanguagePath(): string
    {
        return lang_path($this->sourceLanguage);
    }

    private function ensureDirectoryExists(string $path): void
    {
        if (!File::isDirectory($path)) {
            File::makeDirectory($path, 0755, true);
        }
    }

    private function createGoogleTranslateClient(): GoogleTranslate
    {
        $translator = new GoogleTranslate();
        return $translator
            ->setSource($this->sourceLanguage)
            ->setTarget($this->targetLanguage);
    }

    private function validateSetup(): void
    {
        if (empty($this->sourceLanguage) || empty($this->targetLanguage)) {
            throw new Exception('Source and target languages must be set');
        }
        
        if ($this->sourceLanguage === $this->targetLanguage) {
            throw new Exception('Source and target languages cannot be the same');
        }
    }

    private function validateSourceDirectory(string $path): void
    {
        if (!File::isDirectory($path)) {
            throw new Exception("Source language directory not found: {$path}\n" .
                "Have you run `php artisan lang:publish` command?");
        }
    }

    private function validateLanguageCode(string $code): void
    {
        if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $code)) {
            throw new Exception("Invalid language code format: {$code}");
        }
    }

    private function log(string $message): void
    {
        if ($this->output) {
            $this->output->writeln("<comment>{$message}</comment>");
        }
        Log::info($message);
    }

    private function logError(string $message): void
    {
        if ($this->output) {
            $this->output->writeln("<error>{$message}</error>");
        }
        Log::error($message);
    }
}
