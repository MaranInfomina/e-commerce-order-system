import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import Pagination from '../components/Pagination.vue'

describe('Pagination', () => {
  it('disables previous on the first page', () => {
    const wrapper = mount(Pagination, { props: { currentPage: 1, lastPage: 5 } })

    expect(wrapper.get('[data-test="prev"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="next"]').attributes('disabled')).toBeUndefined()
  })

  it('disables next on the last page', () => {
    const wrapper = mount(Pagination, { props: { currentPage: 5, lastPage: 5 } })

    expect(wrapper.get('[data-test="prev"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.get('[data-test="next"]').attributes('disabled')).toBeDefined()
  })

  it('disables both when there is a single page', () => {
    const wrapper = mount(Pagination, { props: { currentPage: 1, lastPage: 1 } })

    expect(wrapper.get('[data-test="prev"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="next"]').attributes('disabled')).toBeDefined()
  })

  it('emits the target page when a control is clicked', async () => {
    const wrapper = mount(Pagination, { props: { currentPage: 3, lastPage: 5 } })

    await wrapper.get('[data-test="next"]').trigger('click')
    await wrapper.get('[data-test="prev"]').trigger('click')

    expect(wrapper.emitted('change')).toEqual([[4], [2]])
  })

  it('clamps a currentPage beyond lastPage to lastPage in the status text, and still disables next', () => {
    const wrapper = mount(Pagination, { props: { currentPage: 999, lastPage: 4 } })

    expect(wrapper.get('[data-test="status"]').text()).toBe('Page 4 of 4')
    expect(wrapper.get('[data-test="next"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="prev"]').attributes('disabled')).toBeUndefined()
  })

  it('clamps a currentPage below 1 to 1 in the status text, and still disables previous', () => {
    const wrapper = mount(Pagination, { props: { currentPage: 0, lastPage: 4 } })

    expect(wrapper.get('[data-test="status"]').text()).toBe('Page 1 of 4')
    expect(wrapper.get('[data-test="prev"]').attributes('disabled')).toBeDefined()
  })

  it('shows an in-range currentPage unclamped', () => {
    const wrapper = mount(Pagination, { props: { currentPage: 2, lastPage: 4 } })

    expect(wrapper.get('[data-test="status"]').text()).toBe('Page 2 of 4')
  })
})
