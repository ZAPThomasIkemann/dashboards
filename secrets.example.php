<?php
// ================================================================
// secrets.example.php – Vorlage für lokale Zugangsdaten
// ================================================================
// Kopiere diese Datei nach secrets.php und trage die echten Keys ein.
// secrets.php ist in .gitignore und wird NICHT ins Repository eingecheckt.
//
// Auf dem Produktionsserver liegt die ausgefüllte secrets.php unter
// dem gleichen Pfad relativ zum Repository-Root.
// ================================================================

// AI-Anbieter: 'openai' oder 'anthropic'
define('AI_PROVIDER', 'anthropic');

// OpenAI (https://platform.openai.com/api-keys)
define('OPENAI_API_KEY',      '');
define('OPENAI_MODEL_FAST',   'gpt-4.1-mini');
define('OPENAI_MODEL_STRONG', 'gpt-5.5');

// Anthropic (https://console.anthropic.com/settings/keys)
define('ANTHROPIC_API_KEY',      '');
define('ANTHROPIC_MODEL_FAST',   'claude-sonnet-4-5');   // schnell + günstig
define('ANTHROPIC_MODEL_STRONG', 'claude-opus-4-5');    // leistungsstark
