<?php

namespace Alisalehi\LaravelLangFilesTranslator\Services;

use Illuminate\Support\Facades\File;
use Stichoza\GoogleTranslate\GoogleTranslate;
use Symfony\Component\Finder\SplFileInfo;
use RuntimeException;

class TranslateService
{
    private string $sourceLanguage;
    private string $targetLanguage;
    private GoogleTranslate $translator;
    private array $translationCache = [];
    private bool $preserveParameters = true;
    private bool $dryRun = false;
    
    public function from(string $language): self
    {
        $this->sourceLanguage = $language;
        return $this;
    }
    
    public function to(string $language): self
    {
        $this->targetLanguage = $language;
        return $this;
    }
    
    public function dryRun(bool $dryRun = true): self
    {
        $this->dryRun = $dryRun;
        return $this;
    }
    
    public function preserveParameters(bool $preserve): self
    {
        $this->preserveParameters = $preserve;
        return $this;
    }
    
    public function translate(): array
    {
        $this->validateLanguages();
        $this->initializeTranslator();
        
        $files = $this->getSourceLanguageFiles();
        $results = [];
        
        foreach ($files as $file) {
            $results[$file->getFilename()] = $this->processFile($file);
        }
        
        return $results;
    }
    
    private function validateLanguages(): void
    {
        if (empty($this->sourceLanguage) || empty($this->targetLanguage)) {
            throw new RuntimeException('Source and target languages must be specified');
        }
        
        if ($this->sourceLanguage === $this->targetLanguage) {
            throw new RuntimeException('Source and target languages cannot be the same');
        }
    }
    
    private function initializeTranslator(): void
    {
        $this->translator = (new GoogleTranslate())
            ->setSource($this->sourceLanguage)
            ->setTarget($this->targetLanguage);
    }
    
    private function getSourceLanguageFiles(): array
    {
        $sourcePath = $this->getSourceLanguagePath();
        
        $this->validateSourceDirectory($sourcePath);
        
        $files = File::files($sourcePath);
        
        if (empty($files)) {
            throw new RuntimeException("No language files found in '{$this->sourceLanguage}' directory");
        }
        
        return $files;
    }
    
    private function getSourceLanguagePath(): string
    {
        return lang_path($this->sourceLanguage);
    }
    
    private function validateSourceDirectory(string $path): void
    {
        if (!File::isDirectory($path)) {
            throw new RuntimeException(
                "Language directory '{$this->sourceLanguage}' does not exist. " .
                "Have you run `php artisan lang:publish` command?"
            );
        }
    }
    
    private function processFile(SplFileInfo $file): bool
    {
        $translations = include $file->getPathname();
        
        if (!is_array($translations)) {
            return false;
        }
        
        $translatedData = $this->translateArray($translations);
        $phpContent = $this->generatePhpFileContent($translatedData);
        
        if (!$this->dryRun) {
            $this->saveTranslatedFile($file->getFilename(), $phpContent);
        }
        
        return true;
    }
    
    private function translateArray(array $data): array
    {
        $translated = [];
        
        foreach ($data as $key => $value) {
            $translated[$key] = is_array($value) 
                ? $this->translateArray($value) 
                : $this->translateString($value);
        }
        
        return $translated;
    }
    
    private function translateString(string $text): string
    {
        if (isset($this->translationCache[$text])) {
            return $this->translationCache[$text];
        }
        
        $processedText = $this->preserveParameters 
            ? $this->protectParameters($text) 
            : $text;
            
        $translated = $this->translator->translate($processedText);
        
        if ($this->preserveParameters) {
            $translated = $this->restoreParameters($translated);
        }
        
        $this->translationCache[$text] = $translated;
        
        return $translated;
    }
    
    private function protectParameters(string $text): string
    {
        return preg_replace_callback(
            '/(:\w+)/',
            fn($match) => '{' . $match[0] . '}',
            $text
        );
    }
    
    private function restoreParameters(string $text): string
    {
        return str_replace(['{', '}'], '', $text);
    }
    
    private function generatePhpFileContent(array $data): string
    {
        $export = var_export($data, true);
        return "<?php\n\nreturn {$export};";
    }
    
    private function saveTranslatedFile(string $filename, string $content): void
    {
        $targetDir = lang_path($this->targetLanguage);
        
        if (!File::isDirectory($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }
        
        File::put("{$targetDir}/{$filename}", $content);
    }
}
