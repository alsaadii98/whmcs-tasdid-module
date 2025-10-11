function tasdidPayment() {
  return {
    loading: false,
    payId: null,
    paymentUrl: null,

    initPayment() {
      this.loading = true;

      const formData = new FormData();
      formData.append("action", "init_payment");

      fetch(window.location.href, {
        method: "POST",
        body: formData,
      })
        .then((res) => res.json())
        .then((data) => {
          if (data.success) {
            this.payId = data.payId;
            this.paymentUrl = data.paymentUrl;
          } else {
            alert("Payment initialization failed: " + data.error);
          }
        })
        .catch((err) => alert("Error: " + err))
        .finally(() => (this.loading = false));
    },

    goToPayment() {
      if (this.paymentUrl) {
        window.open(this.paymentUrl, "_blank");
        // Call RabbitMQ to notify payment initiation
      }
      // window.open(this.paymentUrl, '_blank');
    },
  };
}
