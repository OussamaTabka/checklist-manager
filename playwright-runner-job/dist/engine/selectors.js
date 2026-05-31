"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.selectorToLocator = selectorToLocator;
exports.selectorDebugText = selectorDebugText;
function selectorToLocator(page, selector) {
    switch (selector.by) {
        case 'testid':
            return page.getByTestId(selector.id);
        case 'role':
            return page.getByRole(selector.role, {
                name: selector.name,
                exact: selector.exact,
            });
        case 'label':
            return page.getByLabel(selector.text);
        case 'text':
            return page.getByText(selector.text, {
                exact: selector.exact,
            });
        case 'css':
            return page.locator(selector.value);
        default: {
            const unreachable = selector;
            throw new Error(`Unsupported selector ${JSON.stringify(unreachable)}`);
        }
    }
}
function selectorDebugText(selector) {
    switch (selector.by) {
        case 'testid':
            return `testid=${selector.id}`;
        case 'role':
            return `role=${selector.role}, name=${selector.name}`;
        case 'label':
            return `label=${selector.text}`;
        case 'text':
            return `text=${selector.text}`;
        case 'css':
            return `css=${selector.value}`;
        default: {
            const unreachable = selector;
            return JSON.stringify(unreachable);
        }
    }
}
