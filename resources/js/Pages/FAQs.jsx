import { Head } from "@inertiajs/react";
import ContactForm from "@/Components/ContactForm";
export default function FAQs() {
  return (
    <div className="hidden-vault min-h-full bg-[#F8F4E9] text-[#0D0C09]"><div className="page-width max-w-5xl py-12">
      <Head title="Information and contact" />
      <p className="text-xs font-medium uppercase tracking-[0.12em] text-[#3E3A32]">Max’s Collectibles / Information</p>
      <h1 data-hv-type="display" className="my-6 text-[var(--hv-display-sm)] md:text-[var(--hv-display-md)]">A few useful details.</h1>
      <section className="my-8 border border-[#3E3A32] bg-[#E8DDC4] p-5 md:p-6">
        <h2 data-hv-type="display" className="text-[var(--hv-heading-sm)]">Before you buy</h2>
        <div className="space-y-5">
          <details open>
            <summary className="cursor-pointer py-3">
              Where can I find an item’s condition and other facts?
            </summary>
            <p>
              Read its description, supplied facts and photographs on the item
              page. Ask Max if a detail you need is missing.
            </p>
          </details>
          <details id="shipping">
            <summary className="cursor-pointer py-3">
              How do delivery and payment work?
            </summary>
            <p>
              Enter an Australian delivery address at checkout. Review the
              server quote, including any delivery and tax amounts, before
              continuing to payment. A pending checkout is not a payment
              confirmation; check the protected order status.
            </p>
          </details>
          <details id="returns">
            <summary className="cursor-pointer py-3">
              Where are the shipping and returns policies?
            </summary>
            <p>
              Final store policies are being prepared. Please contact Max for
              the current information before purchasing.
            </p>
          </details>
          <details id="privacy">
            <summary className="cursor-pointer py-3">
              How do I find my order?
            </summary>
            <p>
              Signed-in purchases appear in your account. Guest orders use their
              original private access link. Matching an email address does not
              attach guest orders to a new account.
            </p>
          </details>
        </div>
      </section>
      <section id="contact" className="border border-[#3E3A32] bg-[#E8DDC4] p-5 md:p-6">
        <h2 data-hv-type="display" className="text-[var(--hv-heading-sm)]">Contact Max</h2>
        <p className="mb-6">
          A general question about the collection or an order? Leave a message
          below.
        </p>
        <ContactForm />
      </section>
    </div></div>
  );
}
