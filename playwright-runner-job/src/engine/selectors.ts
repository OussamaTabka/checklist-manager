import type { Locator, Page } from 'playwright'
import type { SelectorDsl } from './dsl'

export function selectorToLocator(page: Page, selector: SelectorDsl): Locator {
  switch (selector.by) {
    case 'testid':
      return page.getByTestId(selector.id)

    case 'role':
      return page.getByRole(selector.role as never, {
        name: selector.name,
        exact: selector.exact,
      })

    case 'label':
      return page.getByLabel(selector.text)

    case 'text':
      return page.getByText(selector.text, {
        exact: selector.exact,
      })

    case 'css':
      return page.locator(selector.value)

    default: {
      const unreachable: never = selector
      throw new Error(`Unsupported selector ${JSON.stringify(unreachable)}`)
    }
  }
}

export function selectorDebugText(selector: SelectorDsl): string {
  switch (selector.by) {
    case 'testid':
      return `testid=${selector.id}`
    case 'role':
      return `role=${selector.role}, name=${selector.name}`
    case 'label':
      return `label=${selector.text}`
    case 'text':
      return `text=${selector.text}`
    case 'css':
      return `css=${selector.value}`
    default: {
      const unreachable: never = selector
      return JSON.stringify(unreachable)
    }
  }
}
