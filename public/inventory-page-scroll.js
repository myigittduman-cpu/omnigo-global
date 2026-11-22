import {scrollTableStart} from './table-page-scroll.js';
export const scrollInventoryTable = (root, viewport = globalThis.window) => scrollTableStart(root, '.inventory-table-wrap', viewport);
