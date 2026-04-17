# PI-3A-Web

## AI Assistance for Type Autre

The employee demande creation form now supports AI assistance for the type Autre flow.

### Required environment variables

- HUGGINGFACE_API_KEY: your Hugging Face token
- HUGGINGFACE_MODEL: model id used by the Hugging Face router chat API

Default model in .env:

- katanemo/Arch-Router-1.5B:hf-inference

Set your token locally in .env.local:

HUGGINGFACE_API_KEY=your_token_here

### User flow

1. Choose type Autre.
2. Fill Description initiale pour assistant IA.
3. Click Generer avec IA.
4. AI fills specific fields in Informations specifiques.
5. Click Creer la demande.
6. Confirm in the preview modal to save.
7. If user selects No, they can edit or regenerate with AI.