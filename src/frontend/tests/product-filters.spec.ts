import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ProductFilters from '../components/ProductFilters.vue'

const categories = [
  { id: 1, name: 'Quia Eius', slug: 'quia-eius' },
  { id: 2, name: 'Quia Libero', slug: 'quia-libero' },
]

describe('ProductFilters', () => {
  it('seeds its fields from props on mount', () => {
    const wrapper = mount(ProductFilters, {
      props: { categories, search: 'widget', category: 'quia-eius', sort: 'price' },
    })

    expect((wrapper.find('input[type="search"]').element as HTMLInputElement).value).toBe('widget')
    expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('quia-eius')
  })

  it('re-syncs its fields when props change after mount, as on Back navigation', async () => {
    const wrapper = mount(ProductFilters, {
      props: { categories, search: 'widget', category: 'quia-eius', sort: 'price' },
    })

    // Simulate the URL going back to a plain /products with no filters — the
    // page passes new props, but Nuxt reuses this same component instance.
    await wrapper.setProps({ search: '', category: '', sort: '' })

    expect((wrapper.find('input[type="search"]').element as HTMLInputElement).value).toBe('')
    expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('')
  })

  it('emits the current field values, not the stale mount-time props, after a resync', async () => {
    const wrapper = mount(ProductFilters, {
      props: { categories, search: 'widget', category: '', sort: '' },
    })

    await wrapper.setProps({ search: '', category: '', sort: '' })
    await wrapper.find('form').trigger('submit')

    expect(wrapper.emitted('update')).toEqual([[{ search: '', category: '', sort: '' }]])
  })
})
