=== AI Workflow Automation ===
Contributors: massiveshift
Tags: ai, automation, ai agent, ai chatbot, workflow
Requires at least: 6.2
Tested up to: 7.1.1
Requires PHP: 8.0
Stable tag: 2.0.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build AI agents, chatbots, and content workflows in WordPress with a visual, no-code builder. Free with your own API key.

== Description ==

**Website and docs:** [wpaiworkflowautomation.com](https://wpaiworkflowautomation.com)

= What reviewers say =

* "You can design these workflows using an intuitive drag-and-drop editor, and access multiple AI models from the same user interface." ([WPBeginner](https://www.wpbeginner.com/solutions/ai-workflow-automation/))
* "AI Workflow Automation stands out as a powerful solution if you want to leverage AI within the WordPress ecosystem." ([WP Mayor](https://wpmayor.com/ai-workflow-automation-review/), rated 4.5 out of 5)

**Turn WordPress into an AI automation platform, visually, without code.** Drag nodes onto a canvas, connect them, and build real, multi-step workflows and AI agents right inside wp-admin. Draft and optimize content, answer visitors with an AI chatbot, run form-to-AI automations, scrape and research the web, and keep a human in the loop for approvals. Powered by leading models from OpenAI, Anthropic, and OpenRouter: hundreds of models through one interface.

**It is genuinely free.** Bring your own AI provider API key and every workflow runs locally on your own server: unlimited, with no account and nothing sent to us. When you'd rather not manage keys or worry about PHP timeouts, flip any workflow to **Cloud** and run it on our hosted engine with a free connected account. You choose, per workflow, whether it runs **Local** (your key) or **Cloud** (our hosted engine), and you can mix both on the same site.

= Why AI Workflow Automation =

* **A real free tier, not a trial.** The visual builder, ~20 node types, every trigger, local execution, and the chat widget are free forever with your own key (BYOK). No account needed, no lock-in.
* **Visual, multi-step automations and agents.** Not single-shot prompts: chain trigger, AI, logic, and action into genuine automations on a drag-and-drop canvas (React Flow), then watch each run live.
* **AI agent chatbots that *do* things.** Deploy a chatbot that can search your posts and WooCommerce products, show rich cards, and call your workflows as tools, governed by per-tool **approval** gates, rate limits, and one-click **human handoff** to Chatwoot, Zendesk, or Intercom.
* **Human in the loop, built in.** Pause any workflow for a person to review, edit, or approve AI output. Pending approvals and hand-offs collect in a dedicated **Tasks** page and a real-time **Operator Inbox**.
* **Your keys, or ours.** Run on your own key for free, or connect a free account and use hosted AI with no key management at all. Your provider keys never leave WordPress.
* **WordPress-native, agency-ready.** Trigger from core events and the major form plugins, expose workflows as core **Abilities** / **MCP** tools on WordPress 7.0 (opt-in), and manage many client sites from one account.

= Who it's for =

* **Content creators and marketers**: automate drafting, summarizing, SEO optimization, and image sourcing.
* **Agencies and freelancers**: deploy AI automations and chatbots across many client sites, billed centrally.
* **Store owners**: generate product descriptions, route form leads through AI, and post results automatically.
* **Developers**: trigger workflows by webhook, call external APIs, parse JSON/XML/CSV/HTML, and expose workflows to AI agents via Abilities/MCP.

= What you can build (nodes) =

* **Triggers**: manual, scheduled runs, webhooks, and form submissions from Gravity Forms, WPForms, Contact Form 7, Ninja Forms, and Elementor Forms, plus workflow-to-workflow chaining.
* **AI Model node**: generate and transform text with OpenAI, Anthropic (Claude), and OpenRouter; use your key, the site-default provider, or a connected account. Return named, typed multi-output fields that flow straight into later steps.
* **Specialized AI nodes**: sentiment analysis, summarization, information extraction, SEO optimization, and full article writing.
* **Content and data actions**: create or update posts and custom post types (including WooCommerce products and ACF fields), write to custom database tables, find images with Unsplash, generate images/media, generate PDFs from templates or HTML, send email, call external APIs, and post to webhooks.
* **Connect an App**: bring Google (Sheets, Drive, Docs, Gmail), Slack, Notion, GitHub, and more into a workflow as action steps, with a secure one-click account connection.
* **Research and web**: deep web research and Firecrawl-powered scraping/crawling pipelines.
* **Knowledge Base (RAG)**: give your AI a private knowledge base from pasted text, your WordPress content, or uploaded PDF/DOCX/TXT documents.
* **Chatbots**: build an AI chat widget trained on your own data and deploy it anywhere with a shortcode.
* **Logic and control**: conditions and branching, a Loop node, parsers (JSON/XML/CSV/HTML), and a Human Input node for approvals and human-in-the-loop review.
* **MCP client**: connect your workflows to external MCP tools and servers.

= Use cases =

* **Content automation**: turn a topic or brief into a drafted, SEO-optimized post, ready for review.
* **AI chatbots**: answer visitor questions 24/7 with a chatbot trained on your content, deployed via shortcode.
* **Form to AI to action**: take a form submission, process it with AI, and email, post, or store the result automatically.
* **Research and scraping pipelines**: crawl sources with Firecrawl, research a subject, then summarize and extract structured data.
* **Human-approval flows**: pause a workflow for a human to review, edit, or approve AI output before it goes live.
* **Agentic tasks (WP 7.0)**: let an AI assistant call your workflows as Abilities/MCP tools, with spend limits you control.

= Free vs. Cloud, your choice, per workflow =

* **Local (free, BYOK).** Builder, core nodes, triggers, chat widget, and local execution are free forever. AI calls go directly from your site to the provider you configured, using your key. Your key **never leaves your WordPress install**. No account required.
* **Cloud.** Connect a free account to run workflows on our engine with our keys: no key management, no PHP timeouts, server-side scheduling, and premium nodes.


== External services ==

This plugin can connect to external services. **In the free, local (BYOK) configuration it never contacts our platform.** Each connection below happens only in the situation described.

**1. AI Workflow Automation platform (accounts and cloud execution)**

Our hosted account, AI proxy, and workflow execution service. It is contacted only after you create or connect an account, and never in local/BYOK mode.

* **Service domain:** [api.wpaiworkflowautomation.com](https://api.wpaiworkflowautomation.com)
* **What is sent, and when:**
  * *When you create or connect an account:* your email and password, or a Google sign-in token, so we can authenticate you, plus your site URL so the site can be registered and issued a site key.
  * *When an AI node runs in keyless mode:* the prompt or messages, the model name, and the generation parameters for that request, so the call can run on our provider keys and return the result. Your own provider keys are never included.
  * *When a workflow runs in Cloud mode:* the workflow definition and the trigger or input data for that run, so our engine can execute it and return the result. A finished run may perform a small, allow-listed WordPress action back on your site through a signed callback.
  * *While the site stays connected:* your plan status and remaining allowance are fetched periodically so the plugin can display them.
  * *If you redeem a legacy license:* the license key.
* Terms and conditions: [wpaiworkflowautomation.com/terms-and-conditions](https://wpaiworkflowautomation.com/terms-and-conditions/)
* Privacy policy: [wpaiworkflowautomation.com/privacy-policy](https://wpaiworkflowautomation.com/privacy-policy/)

**2. Your AI provider (OpenAI, Anthropic, OpenRouter, or another provider you configure)**

Contacted when an AI node runs in local/BYOK mode, or when you use the WordPress site-default provider.

* **What is sent, and when:** the prompt or messages and the generation parameters for that request, sent directly from your server to the provider you configured, using your own key, at the moment the node runs. Nothing passes through us.
* Review the terms and privacy policy of the provider you choose, for example https://openai.com/policies , https://www.anthropic.com/legal/privacy , or https://openrouter.ai/privacy .

**3. Template library ([wpaiworkflowautomation.com](https://wpaiworkflowautomation.com))**

Contacted only when you open the Templates screen or import a template.

* **Service domain:** [wpaiworkflowautomation.com](https://wpaiworkflowautomation.com)
* **What is sent, and when:** a request for the template list or for one template by slug, at the moment you browse or import. If a legacy license key is still stored on the site, it is included so entitled templates can be returned. No workflow or site content is sent.

**4. Paddle (payment processing)**

Our merchant of record, contacted only if you choose to buy a paid plan or a top-up.

* **What is sent, and when:** the plugin asks our platform for a checkout session and opens Paddle's hosted checkout page; the billing details you enter there go to Paddle. The plugin never loads Paddle scripts on your site and never handles or stores card data.
* Terms: https://www.paddle.com/legal/terms
* Privacy policy: https://www.paddle.com/legal/privacy

**5. Usage analytics (opt-in, off by default)**

Optional and disabled by default. You are asked once, on the first screen of the getting-started walkthrough, with the box unticked. Nothing is sent unless you tick it there or switch on "Usage Analytics" in the plugin settings, and you can switch it off again at any time in Settings.

* **Service domain:** [api.wpaiworkflowautomation.com](https://api.wpaiworkflowautomation.com)
* **What is sent, and when:** only after you opt in: a random installation ID that is not derived from your site address, the plugin version, and a short usage event such as "installed", "finished setup", "connected an AI provider", "created a workflow" or "a workflow ran successfully". Each of those is sent once per installation. Once a day the plugin also sends counts only: how many workflows exist, how many executions ran in the last 7 and 30 days, and how many nodes of each type are in use. Your site address, site name, email address, API keys, prompts, workflow content and execution content are never sent, and there is no field in the payload that can carry them.
* Terms and conditions: [wpaiworkflowautomation.com/terms-and-conditions](https://wpaiworkflowautomation.com/terms-and-conditions/)
* Privacy policy: [wpaiworkflowautomation.com/privacy-policy](https://wpaiworkflowautomation.com/privacy-policy/)

== Frequently Asked Questions ==

= Is it really free? =

Yes. With your own AI provider API key, the visual builder, all node types, triggers, and a chat widget run locally on your server: unlimited, with no account and no data sent to us. The cloud service is entirely optional.

= Do I need to pay? =

No. Bring your own AI provider key and everything runs locally and free, forever. Our optional cloud service only comes into play if you choose keyless AI or hosted execution.

= Do I need an account? =

Only for the optional cloud features. The free local (BYOK) mode needs no account and never contacts our platform. Create a free account (email or Google) only if you want keyless AI or hosted cloud execution.

= Do I need an API key? =

For the free local mode, yes: you bring your own key (BYOK) from a provider like OpenAI or OpenRouter, and pay that provider directly for usage. If you'd rather not manage a key, connect an account and use **keyless** AI instead. You need one or the other, not both.

= What is the difference between BYOK and Cloud? =

**BYOK (bring your own key)** runs your workflows locally using your own provider key: free, and your key never leaves WordPress. **Cloud** is our hosted option: connect an account and run AI or whole workflows on our servers using our keys, with no key management. You choose which mode each workflow uses.


= Is my data safe? Do my API keys leave my site? =

In free local/BYOK mode, no data is sent to our platform, and your provider keys are stored encrypted and used only for direct calls from your server to the provider: they are never transmitted to us, even in cloud mode. If you opt in to the cloud service, only the data listed under "AI Workflow Automation platform" in the External services section above is sent.

= What are the WordPress and PHP requirements? =

WordPress 6.2 or higher and PHP 8.0 or higher. The plugin is fully functional on 6.x; the WordPress 7.0 agent features (exposing workflows as core **Abilities** and over the **MCP Adapter**) light up automatically when you're on 7.0.

= Can I use it on multiple sites (agencies)? =

Yes. The free local mode works on any number of sites, with no account and no limit. If you connect a cloud account, the Free and Pro plans cover one production site at a time and you can move your account between sites whenever you like, at no cost. Development and staging sites never count. The Business plan connects up to 50 client sites from one account, with per-site usage and budgets.

= Can I cancel anytime? =

Yes. Paid plans are month-to-month and you can cancel at any time from your account, after which you simply keep using the free local (BYOK) mode.

= Do I need coding skills? =

No. The visual drag-and-drop builder lets you create complex, AI-powered workflows and agents without writing code.

== Screenshots ==

1. Visual workflow builder: a lead intake automation on the drag and drop canvas that branches from an AI drafted reply to an email and a saved draft, shown on the redesigned dark theme.
2. Interactive AI generator: describe what you want, answer a few quick questions, and it builds a complete, editable workflow for you.
3. AI agent on the front end of your site: it answers visitors and replies with rich cards and quick reply chips, right inside your own WordPress theme.
4. Connect an App: link Google Sheets and hundreds of other apps, then choose an action and map the fields right on the canvas.
5. Live execution: watch each workflow run with per node status, timing, and the full input and output for every step.
6. Human in the loop: review, approve, or edit what the AI drafted before it is sent or published.

== Changelog ==

= 2.0.8 =
* New: the getting-started walkthrough now asks once, on its first screen, whether you want to share anonymous usage data. The box is unticked, it says exactly what is collected, and you can change it any time in Settings. Nothing is sent unless you say yes.
* Fixed: a Post node that creates a WooCommerce product now writes the price, sale price, SKU, stock status and stock quantity, and saves any other field you map as a custom field. Before, the product was created with no price, no SKU and no custom fields. Cloud runs write the same fields.
* Fixed: replaced the long dashes in on-screen text with plain punctuation, so nothing shows as a stray character.
* Fixed: a Cloud run now carries every setting you chose on a node. The Media Generator node lost the model and the prompt, Connect an App lost the app and the action, Send Email always sent plain text and dropped cc and bcc, and the AI Model node lost its structured output schema.
* Fixed: a post created by a Cloud run now carries the excerpt as well as the title, content, status and post type.
* Fixed: line breaks in an AI Model prompt are kept in Local mode, instead of being collapsed into one line.
* New: a Loop node that repeats over a list now runs in Cloud.
* New: anything Cloud cannot run is refused before the run starts, with the reason, instead of failing part way through. That covers the Document Parser, Firecrawl set to map, search or agent, a delayed Send Email, a Knowledge Base on the AI Model node, a task assigned to a WordPress user or role, and a Post node that sets the author, categories, a featured image, product images, ACF fields or a future date. Those workflows run Locally as before.
* New: if your plan has no site slot left, connecting now offers to move your account to this site, or to upgrade, instead of only reporting an error. Development sites never count toward the limit.
* Fixed: the setup wizard keeps an API key you paste and it verifies, whichever way you close the wizard afterwards. Before, the key could be dropped and the first run failed saying no key was set.
* Fixed: the system report no longer writes PHP notices to the debug log when permalinks are set to plain, and neither does the Chats page on a site that has no chats yet.
* Fixed: line breaks in an AI result now show as real line breaks in the execution details and in the live execution panel, instead of as a stray HTML tag.
* Fixed: a spelling mistake in the confirmation message shown after you approve or edit a human task.

= 2.0.7 =
* New: setup now opens on a simple choice: use the free cloud credits that come with a connected account, or bring your own API key. The key step can be skipped and set up later.
* New: setup ends with a real workflow run on your 50 free cloud credits, so you see a finished result before anything else.
* New: activating the plugin now opens it for you and shows a one-time welcome.
* New: you stay signed in to your account for as long as you use it, with a quick re-check only for sensitive actions.
* Fixed: API keys are verified before they are saved, so a wrong key is caught right away instead of failing on the first run.
* Fixed: the walkthrough now shows the real bonus credit amounts.
* Fixed: the bonus credits panel no longer asks for your email address, and no longer covers the buttons underneath it.
* Fixed: plan features are readable on the dark theme.
* Fixed: the workflow list now shows cloud runs, and refreshes as soon as setup finishes.
* Fixed: the AI Model node uses the incoming input when its own prompt is left empty, in both local and cloud runs.
* Fixed: the setup wizard no longer comes back after you dismiss it.
* Improved: checkout reliability when you buy a plan or a top-up.

= 2.0.6 =
* Fixed: structured output fields named "content" now resolve correctly into downstream nodes.
* Fixed: when a Post node has multiple inputs, the content fallback no longer picks a bare media URL over the article body.
* Fixed: connecting a brand-new account no longer shows a misleading "rotate your site key" error; you now get a clear message when the account simply needs its email confirmed.
* Fixed: after you confirm your email, sign-up now continues on its own and finishes connecting the site, instead of leaving the Account page stuck on an error.
* Fixed: the workspace created when you sign up is now named from your first name or site title, rather than always from your email address.

= 2.0.5 =
* Fixed: Post node produced empty posts when a workflow ran in Cloud mode (the cloud payload now reads the node's field mappings correctly).
* Fixed: unresolved field tags no longer leak as literal text into post titles; title and content now fall back consistently.
* Fixed: the execution details panel showed an empty configuration for Post nodes regardless of the real settings.

= 2.0.4 =
* Load React from WordPress core instead of a third-party CDN, for WordPress.org compliance.
* Now requires WordPress 6.2 or later, which bundles the React 18 runtime the front-end viewer needs.

= 2.0.3 =
* App connections: smoother account switching and clearer status when an app needs to be reconnected.
* Security: continued hardening across sign-in, account handling, and inbound webhooks.
* UI polish: spacing, contrast, and small interaction fixes across the redesigned builder.
* Added a full External Services disclosure, including the optional Connect-an-App authentication provider.

= 2.0.2 =
* Reliability fixes for cloud usage tracking and account connection.
* Further UI polish across the builder and settings.

= 2.0.1 =
* Post-relaunch fixes: connection stability and minor builder refinements.

= 2.0.0 =
* Major relaunch. One plugin, free to install, with an optional cloud service. The separate Lite/Pro split and the old license system are retired: all node types are free in local (BYOK) mode.
* New: per-workflow **execution mode**: run **Local** (free, your own keys) or **Cloud** (our engine and keys).
* New: **keyless AI**: route AI calls through our hosted proxy so no provider key is required. Your own provider keys never leave WordPress.
* New: your chatbot is now an **AI agent**: it can reason step by step and take actions (run your workflows and tools, search your site, and more), with the simple chatbot still working as before.
* New: **live human handoff** to Chatwoot, Zendesk, or Intercom, plus an **Operator Inbox** to watch conversations, approve the agent's actions, and take over.
* New: **interactive chat messages**: product and content cards, carousels, quick-reply buttons, and WooCommerce Add to cart inside the chat, plus opt-in file uploads and chat memory.
* New nodes: **Connect an App** (Google, Slack, Notion, GitHub, and more as action steps), **Generate PDF** (six templates or your own HTML, with a free live preview), **Knowledge Base** (RAG over pasted text, your content, or uploaded PDF/DOCX/TXT), a **Loop** node, and **structured multi-output** from the AI node.
* New: **interactive workflow generator** (asks clarifying questions and lets you pick the model), an **in-canvas AI assistant** command bar, a **live execution panel**, new-user onboarding, and a **Usage Center**.
* New: **connected accounts** with **Google sign-in** and a redesigned **Account** panel with in-builder usage display.
* New: **agency multi-site** management (per-site usage, per-site caps, and provisioning from one owner-only console).
* New: WordPress 7.0 agent surface: expose workflows as core **Abilities** and over the **MCP Adapter** (opt-in, off by default), with per-workflow spend controls.
* New: fully redesigned dark builder UI, a dynamic AI model catalog (live provider model lists with caching and fallback), a rebuilt chat widget fully isolated from your theme, and a redesigned email compose node.
* Improved: Firecrawl node rebuilt for Firecrawl v2, media generator with a live model catalog, friendly variable pills with a "/" picker, and a settings overhaul with a working diagnostic-log download.
* Reliability: existing 1.x license holders are automatically migrated when they connect, progressive live run status, and roughly 90% cheaper repeat generator/assistant calls via prompt caching.
* Security: removed a legacy license secret and a dev auth backdoor, signed and replay-protected cloud-to-WordPress callbacks, blocked signup abuse, and stopped log/nonce leaks in the browser console.
* Modernized build (Vite / React 18) and encryption (AES-256-GCM); verified on WordPress 7.0 / PHP 8.3.

= 1.9.0 =
* Web scraping and crawling rebuilt with cleaner Scrape, Crawl, Map, and Search operations.
* Media generator now loads a live image/video/audio model catalog instead of a fixed list.
* Email node redesigned into a proper compose window; variable references now show as friendly pills.
* Redesigned chat widget and canvas annotations; settings overhaul with a working diagnostic-log download.
* Fixes: reasoning-model parameter errors, local content nodes forcing one provider, hidden WooCommerce product search, and chat-widget theme bleed.

= 1.8.5 =
* Added Elementor Forms trigger support.
* Table creation fixes and shortcode system improvements.
* Chatbot design enhancements.

= 1.8.3 =
* Security improvements.
* Fixed shortcode loading issues.
* Improved AI model node visibility.

= 1.8.2 =
* Critical fix for Google Sheets and Drive output.
* Corrected webhook test functionality.

= 1.8.1 =
* Improved Chat Actions reliability and added streaming and actions support together.
* Webhook receiver enhancements.

= 1.8.0 =
* New MCP Client node for Notion, Google, Gmail, Slack, and GitHub.
* Redesigned builder with a collapsible sidebar and comprehensive onboarding.
* In-workflow API setup.

= 1.7.1 =
* Added Author and Categories support to the Post node.
* Scheduling bug fixes.

= 1.7.0 =
* New Workflow Assistant (Beta) with Chat and Assistant modes and natural-language workflow editing.
* Persistent conversation history for chat nodes.

= 1.6.1 =
* Bug fixes and improvements.

= 1.6.0 =
* New multimedia generator node with text-to-image and text-to-video.
* New Create File node for TXT, DOCX, and HTML.
* Chatbot streaming and page-context improvements.

= 1.5.4 =
* Whitelabel customization for agencies.
* Dynamic AI model support for a large model catalog.
* Vector store management and workflow embedding.

= 1.4.2 =
* Previous public release on WordPress.org.

== Upgrade Notice ==

= 2.0.8 =
Cloud runs now carry every node setting, and anything Cloud cannot run is refused before the run with the reason. Multi-line prompts keep their line breaks. Recommended for everyone.

= 2.0.7 =
A guided setup with free cloud credits and verified API keys, plus fixes to the setup wizard, the workflow list, and the AI Model node. Recommended for everyone.

= 2.0.6 =
Fixes account connection for new sign-ups, plus two Post node content fixes. Recommended for everyone.

= 2.0.3 =
Major relaunch. If you are updating from 1.4.x, your workflows and settings are preserved. The plugin now includes everything that used to be Pro, free with your own AI provider key (BYOK), plus an optional cloud service. The old Lite/Pro split and license system are retired; existing license holders keep their benefits when they connect an account. See the Account page after updating.
