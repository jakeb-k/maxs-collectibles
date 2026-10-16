import { Head } from "@inertiajs/react";
import ContactForm from "@/Components/ContactForm";
export default function FAQs() {
  return (
    <div className="page-width py-12 max-w-5xl">
      <Head title="Information and contact" />
      <p className="eyebrow">Max’s Collectibles / Information</p>
      <h1 className="my-6">A few useful details.</h1>
      <section className="surface my-8">
        <h2>Before you buy</h2>
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
      <section id="contact" className="surface">
        <h2>Contact Max</h2>
        <p className="mb-6">
          A general question about the collection or an order? Leave a message
          below.
        </p>
        <ContactForm />
      </section>
    </div>
  );
}
