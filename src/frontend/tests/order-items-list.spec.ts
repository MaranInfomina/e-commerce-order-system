import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import OrderItemsList from '../components/OrderItemsList.vue'
import type { OrderItem } from '~/utils/api'

const items: OrderItem[] = [
  {
    product_id: 1,
    product_name: 'Snapshotted Kettle',
    product_sku: 'KET-0001',
    image_url: 'http://localhost:8080/storage/products/1/kettle.jpg',
    unit_price_cents: 1999,
    quantity: 2,
    line_total_cents: 3998,
  },
]

describe('OrderItemsList', () => {
  it('renders the snapshotted name, sku, and line total exactly as given', () => {
    const wrapper = mount(OrderItemsList, { props: { items } })

    const row = wrapper.get('[data-test="item-1"]')
    expect(row.text()).toContain('Snapshotted Kettle')
    expect(row.text()).toContain('KET-0001')
    expect(row.text()).toContain('39.98')
    expect(wrapper.get('img').attributes('src')).toBe(items[0].image_url)
  })

  it('renders a placeholder box, not a broken image, when the snapshot has no image', () => {
    const wrapper = mount(OrderItemsList, {
      props: { items: [{ ...items[0], image_url: null }] },
    })

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('[data-test="thumb-placeholder"]').exists()).toBe(true)
  })
})
