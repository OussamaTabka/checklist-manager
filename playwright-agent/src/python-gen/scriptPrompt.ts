/**
 * Re-exports from the canonical prompt module (../prompts/scriptPrompt.ts).
 * The full prompt implementation lives there; this stub allows python-gen/
 * internal imports to use a stable local path.
 */
export { buildScriptPrompt } from '../prompts/scriptPrompt.js'
export type { GeneratorChecklistItem as ScriptChecklistItem } from '../prompts/scriptPrompt.js'
