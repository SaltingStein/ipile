---
title: Connect an AI model
summary: Connect and test an AI provider for the site's built-in AI features.
order: 98
permission: manage_integrations
draft: true
---

Connect AI models in **Admin → Platform → Integrations → AI models**. Features such as the built-in site assistant use these connections. Each feature chooses its model separately.

## Connect a provider

1. Open **Integrations** and choose a provider under **AI models**: Anthropic Claude, OpenAI, Google Gemini, OpenRouter, Ollama or an OpenAI-compatible server.
2. Enter the requested details from your provider, including its API key where required. For a server you run yourself, enter its address and model name. It must be reachable by this site.
3. Choose a model your provider account can use. If the page allows an empty model, leaving it blank uses the provider's default.
4. Tick the provider's **Enable** checkbox and choose its **Save** button.
5. Choose **Test connection**. The test may send a small prompt and use provider credit. Read the result before using the model in a feature.

Keys are stored encrypted and are never shown again. On later saves, leave a key blank to keep the saved one.

## Change the built-in assistant's model

1. Connect, enable and test the new provider first.
2. In **Admin → Platform → Site assistant**, choose **Built-in assistant**, select the **AI model**, and choose **Save assistant**. This needs permission to manage settings.
3. If no feature needs the old provider, switch it off in **Integrations**.

Turning off a provider makes features that still use it unavailable. A third-party chat widget has its own connection under **Assistant widgets**; changing an AI model here does not change that widget.

## If the connection fails

Check the key, model name, provider credit and whether this server can reach the provider. Some provider accounts restrict which models a key may use. For Anthropic, enter a workspace ID only if your key is not tied to a workspace. Save corrected settings and test again.

See [Set up the site assistant](/help/site-assistant-settings) to choose its source and allowed business figures.
