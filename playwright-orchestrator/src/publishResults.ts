import type { AppConfig } from './config'
import { patchVersionItemsFallback, publishBatchResults } from './laravelClient'
import type { RunnerResultV1 } from './types'

export async function publishResults(
  config: AppConfig,
  runId: string,
  result: RunnerResultV1,
): Promise<'batch' | 'fallback' | 'skipped'> {
  if (!config.enableLaravel || !config.enablePublishResults) {
    return 'skipped'
  }

  if (config.enableBatchResults) {
    try {
      await publishBatchResults(config, runId, result)
      return 'batch'
    } catch (error) {
      if (!config.enableFallbackPatch) {
        throw error
      }
    }
  }

  if (config.enableFallbackPatch) {
    await patchVersionItemsFallback(config, result)
    return 'fallback'
  }

  return 'skipped'
}
