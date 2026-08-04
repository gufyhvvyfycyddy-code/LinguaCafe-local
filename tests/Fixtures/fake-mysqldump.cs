using System;

public static class FakeMysqldump
{
    public static int Main(string[] args)
    {
        Console.WriteLine("-- LinguaCafe M6 testing-only browser acceptance dump");
        string[] tables = {
            "migrations",
            "users",
            "languages",
            "books",
            "chapters",
            "texts",
            "encountered_words",
            "word_senses",
            "review_cards",
            "review_logs"
        };

        foreach (string table in tables)
        {
            Console.WriteLine("CREATE TABLE `" + table + "` (`id` BIGINT);");
        }

        return 0;
    }
}
