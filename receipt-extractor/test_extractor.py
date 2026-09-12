import unittest
from extractor import extract_receipt_data, parse_brazilian_amount, parse_date


class TestExtractor(unittest.TestCase):
    def test_parse_amounts(self):
        self.assertEqual(parse_brazilian_amount("15,00"), 15.00)
        self.assertEqual(parse_brazilian_amount("R$ 35,50"), 35.50)
        self.assertEqual(parse_brazilian_amount("1.250,00"), 1250.00)
        self.assertEqual(parse_brazilian_amount("R$12,00"), 12.00)

    def test_parse_dates(self):
        self.assertEqual(parse_date("11/09/2026"), "2026-09-11")
        self.assertEqual(parse_date("2026-09-11"), "2026-09-11")
        self.assertEqual(parse_date("11 de setembro de 2026"), "2026-09-11")
        self.assertEqual(parse_date("07/set/2026"), "2026-09-07")

    def test_extract_receipt_text_sample(self):
        sample_text = """
        Comprovante de Transferência Pix
        
        Valor: R$ 17,90
        Data da transferência: 11/09/2026 às 14:30:00
        
        De: João Vitor da Silva
        CPF: ***.123.456-**
        
        Para: Calebe Luvizotto
        Chave Pix: 11999998888
        
        ID da transação: E0003816620260911143000123456789
        Autenticação: 9A8B7C6D5E
        """
        result = extract_receipt_data(sample_text)
        self.assertTrue(result["success"])
        self.assertEqual(result["amount"], 17.90)
        self.assertEqual(result["payment_date"], "2026-09-11")
        self.assertEqual(result["transaction_id"], "E0003816620260911143000123456789")
        self.assertIn("João Vitor", result["payer_name"])
        self.assertIn("Calebe", result["recipient_name"])


if __name__ == "__main__":
    unittest.main()
