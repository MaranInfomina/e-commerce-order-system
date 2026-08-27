import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ProductForm from '../components/ProductForm.vue'

const categories = [
  { id: 1, name: 'Kitchen', slug: 'kitchen' },
  { id: 2, name: 'Garden', slug: 'garden' },
]

describe('ProductForm', () => {
  it('renders a message under each field the server rejected', () => {
    const wrapper = mount(ProductForm, {
      props: {
        categories,
        errors: {
          name: ['The name field is required.'],
          price_cents: ['The price cents field must be an integer.'],
        },
        submitting: false,
      },
    })

    expect(wrapper.get('[data-test="error-name"]').text())
      .toBe('The name field is required.')
    expect(wrapper.get('[data-test="error-price_cents"]').text())
      .toBe('The price cents field must be an integer.')
  })

  it('renders no error elements when there are no errors', () => {
    const wrapper = mount(ProductForm, {
      props: { categories, errors: {}, submitting: false },
    })

    expect(wrapper.find('[data-test="error-name"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="error-price_cents"]').exists()).toBe(false)
  })

  it('joins multiple messages for one field', () => {
    const wrapper = mount(ProductForm, {
      props: {
        categories,
        errors: { sku: ['The sku has already been taken.', 'The sku is too long.'] },
        submitting: false,
      },
    })

    expect(wrapper.get('[data-test="error-sku"]').text())
      .toBe('The sku has already been taken. The sku is too long.')
  })

  it('emits the typed payload on submit', async () => {
    const wrapper = mount(ProductForm, {
      props: { categories, errors: {}, submitting: false },
    })

    await wrapper.get('[data-test="field-name"]').setValue('Titanium Kettle')
    await wrapper.get('[data-test="field-slug"]').setValue('titanium-kettle')
    await wrapper.get('[data-test="field-sku"]').setValue('KET-0001')
    await wrapper.get('[data-test="field-price"]').setValue('12999')
    await wrapper.get('[data-test="field-stock"]').setValue('10')
    await wrapper.get('[data-test="field-category"]').setValue('1')
    await wrapper.get('form').trigger('submit')

    const submitted = wrapper.emitted('submit')?.[0]?.[0]

    expect(submitted).toMatchObject({
      category_id: 1,
      name: 'Titanium Kettle',
      slug: 'titanium-kettle',
      sku: 'KET-0001',
      price_cents: 12999,
      stock_quantity: 10,
    })
    expect(typeof (submitted as { price_cents: number }).price_cents).toBe('number')
  })

  it('disables the submit button while submitting', () => {
    const wrapper = mount(ProductForm, {
      props: { categories, errors: {}, submitting: true },
    })

    expect(wrapper.get('[data-test="submit"]').attributes('disabled')).toBeDefined()
  })

  it('enables the submit button once submitting is no longer true', async () => {
    const wrapper = mount(ProductForm, {
      props: { categories, errors: {}, submitting: true },
    })

    await wrapper.setProps({ submitting: false })

    expect(wrapper.get('[data-test="submit"]').attributes('disabled')).toBeUndefined()
  })

  it('clears a field error once the parent stops reporting it, as after a fix and resubmit', async () => {
    const wrapper = mount(ProductForm, {
      props: {
        categories,
        errors: { name: ['The name field is required.'] },
        submitting: false,
      },
    })

    expect(wrapper.find('[data-test="error-name"]').exists()).toBe(true)

    // Mirrors the real page: it resets fieldErrors to {} at the start of the
    // next submit, so a fixed field must not keep showing the old message.
    await wrapper.setProps({ errors: {} })

    expect(wrapper.find('[data-test="error-name"]').exists()).toBe(false)
  })
})
