<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiService
{
    protected $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.key');
    }

    public function generateDescription($productName, $categoryName = null)
    {
        if (!$this->apiKey) {
            return "Для генерации описания необходимо настроить GEMINI_API_KEY в файле .env";
        }

        try {
            $prompt = "Напиши привлекательное и продающее описание для товара: '{$productName}'" . 
                      ($categoryName ? " в категории '{$categoryName}'" : "") . 
                      ". \n\nТребования к оформлению:\n" .
                      "1. Раздели текст на логические абзацы (минимум 2-3).\n" .
                      "2. Используй список с буллитами (символ * или •) для ключевых преимуществ.\n" .
                      "3. Текст должен быть на русском языке.\n" .
                      "4. Не используй вступления вроде 'Вот описание:'.\n" .
                      "5. Используй двойные переносы строк между разделами для лучшей читаемости.\n" .
                      "6. НЕ ИСПОЛЬЗУЙ Markdown разметку (никаких двойных звездочек ** ). Текст должен быть чистым.";


            $response = Http::post("https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key={$this->apiKey}", [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ]
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? "Не удалось получить текст от ИИ.";
                
                // Очищаем текст от символов жирного шрифта Markdown
                return str_replace('**', '', $text);
            }

            Log::error("Gemini API Error Status: " . $response->status());
            Log::error("Gemini API Error Body: " . $response->body());
            
            return "Ошибка при обращении к ИИ: " . $response->status();

        } catch (\Exception $e) {
            Log::error("AI generation failed: " . $e->getMessage());
            return "Ошибка связи с сервисом ИИ.";
        }
    }
}
